/**
 * 抓取「学校—专业」对照数据，生成 apps/api/database/data/campus_majors.json。
 *
 * 数据源（均为中国教育在线「掌上高考」公开静态数据）：
 *  - 学校清单：static-data.gaokao.cn 的院校基础信息，用于拿到 school_id、院校代码与省份。
 *  - 学校专业：static-data.gaokao.cn 的院校专业列表（含专业名称、培养层次）。
 *  - 专业代码：static-data.gaokao.cn 的专业详情，用于补全专业代码与学科门类。
 *
 * 用法：node scripts/campus-majors/fetch-campus-majors.mjs
 * 可选环境变量：FETCH_CONCURRENCY（默认 6）、FETCH_LIMIT（只处理前 N 所学校，便于调试）
 */

import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../..')
const institutionsPath = path.join(repoRoot, 'apps/api/database/data/china_higher_education_institutions_2026.json')
const outputPath = path.join(repoRoot, 'apps/api/database/data/campus_majors.json')
const catalogCachePath = path.join(os.tmpdir(), 'campus-majors-school-catalog.json')

const CONCURRENCY = Number(process.env.FETCH_CONCURRENCY || 6)
const LIMIT = Number(process.env.FETCH_LIMIT || 0)

const HEADERS = {
  'User-Agent':
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
  Referer: 'https://www.gaokao.cn/',
}

/** 本科专业代码前两位 => 学科门类（教育部 2021 版目录编号） */
const CATEGORY_BY_PREFIX = {
  '01': '哲学',
  '02': '经济学',
  '03': '法学',
  '04': '教育学',
  '05': '文学',
  '06': '历史学',
  '07': '理学',
  '08': '工学',
  '09': '农学',
  10: '医学',
  11: '管理学',
  12: '管理学',
  13: '艺术学',
  14: '交叉学科',
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

async function fetchJson(url, tries = 3) {
  for (let attempt = 1; attempt <= tries; attempt += 1) {
    try {
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), 20000)
      const response = await fetch(url, { headers: HEADERS, signal: controller.signal })
      clearTimeout(timer)
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
      return await response.json()
    } catch (error) {
      // 4xx 属于确定性失败（如学校 id 不存在），不再重试
      if (/^HTTP 4\d\d$/.test(error.message)) return null
      if (attempt === tries) {
        console.warn(`[warn] ${url} => ${error.message}`)
        return null
      }
      await sleep(300 * attempt)
    }
  }
  return null
}

async function runPool(items, limit, worker) {
  let index = 0
  let done = 0
  const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (index < items.length) {
      const current = index
      index += 1
      await worker(items[current], current)
      done += 1
      if (done % 100 === 0) console.log(`[progress] ${done}/${items.length}`)
    }
  })
  await Promise.all(runners)
}

function provinceBase(value) {
  return String(value || '')
    .replace(/壮族|回族|维吾尔|特别行政区|自治区|省|市/g, '')
    .trim()
}

function categoryOf(code) {
  if (!code) return null
  const normalized = String(code).trim()
  if (!/^\d{6}/.test(normalized)) return null
  return CATEGORY_BY_PREFIX[normalized.slice(0, 2)] || null
}

function readCatalogCache() {
  if (!fs.existsSync(catalogCachePath)) return null
  try {
    const ageMs = Date.now() - fs.statSync(catalogCachePath).mtimeMs
    if (ageMs > 24 * 60 * 60 * 1000) return null
    const data = JSON.parse(fs.readFileSync(catalogCachePath, 'utf8'))
    return Array.isArray(data) && data.length > 0 ? data : null
  } catch {
    return null
  }
}

async function fetchSchoolCatalog() {
  const cached = readCatalogCache()
  if (cached) {
    console.log(`[2/4] 命中院校清单缓存（${cached.length} 条）`)
    return cached
  }

  // 院校库搜索接口有频率限制，这里改用静态数据按 school_id 枚举，
  // 既能拿到名称/省份，也能拿到 5 位院校代码用于和教育部名单对齐。
  const maxId = Number(process.env.FETCH_ID_MAX || 6000)
  const ids = Array.from({ length: maxId }, (_, index) => index + 1)
  const catalog = []

  await runPool(ids, CONCURRENCY, async (id) => {
    const payload = await fetchJson(`https://static-data.gaokao.cn/www/2.0/school/${id}/info.json`, 1)
    const data = payload?.data
    if (!data?.name) return
    const codeEnroll = String(data.code_enroll || '')
    catalog.push({
      schoolId: String(id),
      name: String(data.name).replace(/\s+/g, ''),
      province: provinceBase(data.province_name),
      code5: String(data.zs_code || codeEnroll.slice(0, 5)),
      level: data.level_name || '',
    })
  })

  if (catalog.length > 0) {
    fs.writeFileSync(catalogCachePath, JSON.stringify(catalog), 'utf8')
  }

  return catalog
}

async function fetchSchoolMajors(schoolId) {
  const payload = await fetchJson(
    `https://static-data.gaokao.cn/www/2.0/school/${schoolId}/special/list.json`,
  )
  const items = payload?.data
  if (!Array.isArray(items)) return []
  return items
    .filter((item) => item && item.name)
    .map((item) => ({
      name: String(item.name).trim(),
      specialId: String(item.special_id || ''),
      level: item.level_name ? String(item.level_name) : '本科(普通)',
    }))
}

async function main() {
  console.log('[1/4] 读取教育部高校名单…')
  const institutions = JSON.parse(fs.readFileSync(institutionsPath, 'utf8')).institutions
    .filter((item) => item.catalog_type === 'ordinary')
    .slice(0, LIMIT > 0 ? LIMIT : undefined)
  console.log(`[1/4] 待匹配普通高校 ${institutions.length} 所`)

  console.log('[2/4] 抓取掌上高考院校清单…')
  const catalog = await fetchSchoolCatalog()
  console.log(`[2/4] 院校清单 ${catalog.length} 条`)

  const byCode = new Map()
  const byNameProvince = new Map()
  const byName = new Map()
  for (const item of catalog) {
    if (item.code5) byCode.set(item.code5, item)
    byNameProvince.set(`${item.name}|${item.province}`, item)
    if (!byName.has(item.name)) byName.set(item.name, [])
    byName.get(item.name).push(item)
  }

  const matched = []
  const unmatched = []
  const seenSchoolIds = new Set()
  for (const inst of institutions) {
    const code5 = String(inst.official_school_code || '').slice(5, 10)
    const name = String(inst.name || '').replace(/\s+/g, '')
    const province = provinceBase(inst.province)
    let hit = byCode.get(code5) || byNameProvince.get(`${name}|${province}`)
    if (!hit) {
      const candidates = byName.get(name) || []
      if (candidates.length === 1) hit = candidates[0]
    }
    if (!hit) {
      unmatched.push({ name: inst.name, province: inst.province, code: inst.official_school_code })
      continue
    }
    if (seenSchoolIds.has(hit.schoolId)) continue
    seenSchoolIds.add(hit.schoolId)
    matched.push({ inst, school: hit })
  }
  console.log(`[2/4] 匹配成功 ${matched.length} 所，未匹配 ${unmatched.length} 所`)

  console.log('[3/4] 抓取各校专业清单…')
  const majorsBySchool = new Map()
  await runPool(matched, CONCURRENCY, async (row) => {
    const majors = await fetchSchoolMajors(row.school.schoolId)
    if (majors.length > 0) majorsBySchool.set(row.school.schoolId, majors)
  })
  console.log(`[3/4] 获取到 ${majorsBySchool.size} 所学校的专业清单`)

  console.log('[4/4] 抓取专业代码…')
  const specialIds = new Set()
  for (const majors of majorsBySchool.values()) {
    for (const major of majors) if (major.specialId) specialIds.add(major.specialId)
  }
  const codeById = new Map()
  await runPool([...specialIds], CONCURRENCY, async (specialId) => {
    const payload = await fetchJson(`https://static-data.gaokao.cn/www/2.0/special/${specialId}/info.json`)
    const code = payload?.data?.code
    if (code) codeById.set(specialId, String(code).trim())
  })
  console.log(`[4/4] 专业代码 ${codeById.size}/${specialIds.size} 条`)

  const schools = []
  for (const { inst, school } of matched) {
    const majors = majorsBySchool.get(school.schoolId)
    if (!majors) continue
    const seen = new Set()
    const items = []
    for (const major of majors) {
      const key = `${major.name}|${major.level}`
      if (seen.has(key)) continue
      seen.add(key)
      const code = codeById.get(major.specialId) || null
      items.push({
        name: major.name,
        code,
        category: categoryOf(code),
        level: major.level,
      })
    }
    if (items.length === 0) continue
    schools.push({
      official_school_code: inst.official_school_code,
      name: inst.name,
      province: inst.province,
      majors: items,
    })
  }

  schools.sort((a, b) => String(a.official_school_code).localeCompare(String(b.official_school_code)))

  const majorCount = schools.reduce((sum, school) => sum + school.majors.length, 0)
  const metadata = {
    source_name: '掌上高考（中国教育在线）院校专业库静态数据',
    source_school_api: 'https://static-data.gaokao.cn/www/2.0/school/{school_id}/info.json',
    source_school_majors: 'https://static-data.gaokao.cn/www/2.0/school/{school_id}/special/list.json',
    source_major_info: 'https://static-data.gaokao.cn/www/2.0/special/{special_id}/info.json',
    source_institution_catalog: 'apps/api/database/data/china_higher_education_institutions_2026.json',
    fetched_at: new Date().toISOString(),
    school_count: schools.length,
    major_count: majorCount,
    matched_institutions: matched.length,
    unmatched_institutions: unmatched.length,
    notes:
      '专业清单为第三方平台院校专业库快照，仅覆盖普通高校；专业代码与门类按教育部本科专业代码前缀补全，缺失代码的专业 category 为 null。',
  }

  const body = schools.map((school) => JSON.stringify(school)).join(',\n')
  const text = `{\n"metadata": ${JSON.stringify(metadata, null, 2)},\n"schools": [\n${body}\n]\n}\n`
  fs.writeFileSync(outputPath, text, 'utf8')

  const sizeMb = (Buffer.byteLength(text) / 1024 / 1024).toFixed(2)
  console.log(`[done] 写入 ${outputPath}`)
  console.log(`[done] 学校 ${schools.length} 所，专业 ${majorCount} 条，文件 ${sizeMb} MB`)
  if (unmatched.length > 0) {
    console.log(`[done] 未匹配学校示例：${unmatched.slice(0, 10).map((item) => item.name).join('、')}`)
  }
}

main().catch((error) => {
  console.error(error)
  process.exitCode = 1
})
