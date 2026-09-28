import { createHash } from 'node:crypto'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { setTimeout as delay } from 'node:timers/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const dataDir = path.join(root, 'apps/api/database/data')
const output = path.join(dataDir, 'campus-emblems-supplement')
await mkdir(output, { recursive: true })
const { institutions } = JSON.parse(await readFile(path.join(dataDir, 'china_higher_education_institutions_2026.json'), 'utf8'))
const existing = new Set(JSON.parse(await readFile(path.join(dataDir, 'campus-emblems/manifest.json'), 'utf8')).items.map(item => item.official_school_code))
const cachePath = path.join(root, '.runtime-logs/eol-school-list.json')
let schools
try { schools = JSON.parse(await readFile(cachePath, 'utf8')) } catch { schools = null }
if (!schools) {
  schools = []
  let total = Infinity
  for (let page = 1; schools.length < total && page <= 120; page++) {
    const response = await fetch('https://api.eol.cn/gkcx/api/', {
      method: 'POST', headers: { 'content-type': 'application/json' }, signal: AbortSignal.timeout(15000),
      body: JSON.stringify({ uri: 'apidata/api/gk/school/lists', page, size: 30 }),
    })
    if (!response.ok) throw new Error(`School list HTTP ${response.status}`)
    const result = await response.json()
    if (result.code !== '0000' || !Array.isArray(result.data?.item) || !result.data.item.length) throw new Error('Unexpected school list response')
    total = Number(result.data.numFound)
    if (!Number.isFinite(total) || total > 3600) throw new Error('School list count changed; review before proceeding')
    schools.push(...result.data.item)
    console.log(`School list ${schools.length}/${total}`)
    await delay(500)
  }
  if (new Set(schools.map(item => item.school_id)).size !== schools.length) throw new Error('School list repeated a page')
  await writeFile(cachePath, JSON.stringify(schools))
}
const items = []
const errors = []
for (const school of institutions) {
  if (existing.has(school.official_school_code)) continue
  const matches = schools.filter(item => item.name === school.name && school.province.startsWith(item.province_name) && item.is_logo === '1')
  if (matches.length !== 1) continue
  const source = matches[0]
  const url = `https://static-data.eol.cn/upload/logo/${source.school_id}.jpg`
  try {
    let bytes
    try { bytes = await readFile(path.join(output, `${school.official_school_code}.png`)) } catch {
      try { bytes = await readFile(path.join(output, `${school.official_school_code}.jpg`)) } catch { /* first import */ }
    }
    if (!bytes) {
      const response = await fetch(url, { signal: AbortSignal.timeout(15000), redirect: 'error' })
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
      if (Number(response.headers.get('content-length')) > 524288) throw new Error('Image larger than 512 KiB')
      bytes = Buffer.from(await response.arrayBuffer())
      await delay(150)
    }
    if (bytes.length > 524288 || bytes.length < 128) throw new Error('Invalid image size')
    const extension = bytes.subarray(0, 8).toString('hex') === '89504e470d0a1a0a' ? 'png' : bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255 ? 'jpg' : null
    if (!extension) throw new Error('Only PNG/JPEG emblems are accepted')
    const file = `${school.official_school_code}.${extension}`
    const sha256 = createHash('sha256').update(bytes).digest('hex')
    if (sha256 === '415972a49e88a7076c9f2629dedbecccd42a5c20d82ef572982cedcf64a437d3') throw new Error('Provider placeholder, not a school emblem')
    await writeFile(path.join(output, file), bytes)
    items.push({ official_school_code: school.official_school_code, name: school.name, file,
      sha256, source_name: '中国教育在线·掌上高考公开院校校徽', source_url: url })
    if (items.length % 50 === 0) console.log(`Emblems collected: ${items.length}`)
  } catch (error) {
    errors.push({ name: school.name, official_school_code: school.official_school_code, message: error.message })
    console.log(`Skipped ${school.name}: ${error.message}`)
  }
}
const manifest = { version: 1, source: { source_name: '中国教育在线·掌上高考公开院校校徽', source_url: 'https://www.gaokao.cn/school',
  license: 'School emblems remain the property of their respective institutions; identification only, not an endorsement or open-source grant.',
  retrieved_on: new Date().toISOString().slice(0, 10), matching: 'Exact current school name and province; no fuzzy rename matching' }, items }
await writeFile(path.join(output, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`)
await writeFile(path.join(root, 'artifacts/campus-emblem-collection-errors.json'), JSON.stringify(errors, null, 2))
const covered = new Set([...existing, ...items.map(item => item.official_school_code)])
await writeFile(path.join(root, 'artifacts/campus-emblem-missing.json'), JSON.stringify(institutions.filter(item => !covered.has(item.official_school_code)), null, 2))
console.log(JSON.stringify({ added: items.length, total: covered.size, missing: institutions.length - covered.size, errors: errors.length }))
