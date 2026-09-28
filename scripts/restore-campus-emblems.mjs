import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const relative = 'apps/api/database/data/campus-emblems-supplement'
const manifest = JSON.parse(await readFile(path.join(root, relative, 'manifest.json'), 'utf8'))
if (!/^[a-f0-9]{40}$/.test(manifest.asset_commit)) throw new Error('Pin an immutable asset_commit before release')
const git = args => execFileSync('git', args, { cwd: root, maxBuffer: 1024 * 1024 })
const seen = new Set()
let fetched = false
let total = 0
for (const item of manifest.items) {
  if (!/^\d{10}\.(png|jpg)$/.test(item.file) || item.file.split('.')[0] !== item.official_school_code || seen.has(item.file)) throw new Error('Invalid or duplicate emblem filename')
  if (!/^[a-f0-9]{64}$/.test(item.sha256)) throw new Error('Invalid emblem checksum')
  seen.add(item.file)
  const filename = path.join(root, relative, item.file)
  let bytes
  try { bytes = await readFile(filename) } catch (error) {
    if (error.code !== 'ENOENT' || process.argv.includes('--verify-only')) throw error
    if (!fetched) {
      try { git(['cat-file', '-e', `${manifest.asset_commit}^{commit}`]) } catch {
        git(['fetch', '--no-tags', '--depth=1', 'origin', manifest.asset_commit])
      }
      fetched = true
    }
    bytes = git(['show', `${manifest.asset_commit}:${relative}/${item.file}`])
  }
  if (bytes.length > 524288 || bytes.length < 128 || createHash('sha256').update(bytes).digest('hex') !== item.sha256) throw new Error(`Emblem integrity check failed: ${item.file}`)
  const png = bytes.subarray(0, 8).toString('hex') === '89504e470d0a1a0a'
  const jpeg = bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255
  if (!(item.file.endsWith('.png') ? png : jpeg)) throw new Error(`Invalid image format: ${item.file}`)
  if (!process.argv.includes('--verify-only')) await writeFile(filename, bytes)
  total += bytes.length
}
console.log(`Verified ${seen.size} school emblems (${total} bytes), asset commit ${manifest.asset_commit}`)
