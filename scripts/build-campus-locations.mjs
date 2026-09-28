import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import gcoord from 'gcoord'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const input = process.argv[2]
if (!input) throw new Error('Usage: node scripts/build-campus-locations.mjs <public university JSON>')
const bytes = await readFile(input)
if (createHash('sha256').update(bytes).digest('hex') !== 'bbf0e42db43c6f4fad61ec0e984c2ce25b3bf1b2f140810683e2eeca4936932b') {
  throw new Error('Source checksum differs from the reviewed revision; review its provenance before importing.')
}
const candidates = JSON.parse(bytes)
const { institutions } = JSON.parse(await readFile(path.join(root, 'apps/api/database/data/china_higher_education_institutions_2026.json'), 'utf8'))
const normalize = value => value.replaceAll('（', '(').replaceAll('）', ')').trim()
const items = []
const missing = []
for (const school of institutions) {
  const name = normalize(school.name)
  const points = candidates.filter(point => {
    const candidate = normalize(point.name)
    const suffix = candidate.startsWith(name) ? candidate.slice(name.length) : null
    return point.province === school.province
      && (!school.city || point.city === school.city)
      && (suffix === '' || (suffix !== null && /^\([^()]*(校区|校本部)\)$/.test(suffix) && !/旧|原|停用|筹建/.test(suffix)))
      && Number.isFinite(point.location?.lat) && point.location.lat >= 18 && point.location.lat <= 54
      && Number.isFinite(point.location?.lng) && point.location.lng >= 73 && point.location.lng <= 135
      && typeof point.address === 'string' && point.address.length > 4
  }).map(point => {
    // The upstream Baidu Place v2 collector uses its default BD09 response.
    // Convert once, offline; the app's GPS requests use WGS84.
    const [longitude, latitude] = gcoord.transform([point.location.lng, point.location.lat], gcoord.BD09, gcoord.WGS84)
    return {
      name: point.name, address: point.address, district: point.area,
      latitude: Number(latitude.toFixed(7)), longitude: Number(longitude.toFixed(7)),
    }
  })
  const unique = [...new Map(points.map(point => [`${point.latitude},${point.longitude}`, point])).values()]
  if (!unique.length) { missing.push(school); continue }
  items.push({ official_school_code: school.official_school_code, name: school.name, province: school.province, city: school.city, points: unique })
}
const payload = {
  metadata: {
    version: 1, coordinate_system: 'WGS84', source_coordinate_system: 'BD09',
    source_name: 'ZsTs119/china-university-database (Baidu Place public POI snapshot)',
    source_url: 'https://github.com/ZsTs119/china-university-database',
    source_revision: '36710b5c75779ce50a2ac552924851df54fabf42',
    source_revision_date: '2025-07-01', source_sha256: createHash('sha256').update(bytes).digest('hex'),
    collected_at: null, accuracy: 'reference', conversion: 'gcoord@1.0.7 BD09 -> WGS84',
    school_count: items.length, point_count: items.reduce((sum, item) => sum + item.points.length, 0),
    catalog_count: institutions.length, missing_count: missing.length,
  }, items,
}
await writeFile(path.join(root, 'apps/api/database/data/campus_reference_locations.json'), `${JSON.stringify(payload, null, 2)}\n`)
await writeFile(path.join(root, 'artifacts/campus-location-missing.json'), `${JSON.stringify(missing, null, 2)}\n`)
console.log(JSON.stringify(payload.metadata, null, 2))
