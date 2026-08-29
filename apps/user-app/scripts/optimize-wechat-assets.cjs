const fs = require('node:fs/promises')
const path = require('node:path')
const Jimp = require('jimp')

const buildRoot = path.resolve(__dirname, '..', 'dist', 'build', 'mp-weixin')
const assets = [
  {
    source: 'static/home/hero-campus-reward.png',
    target: 'static/home/hero-campus-reward.jpg',
    maxWidth: 750,
    quality: 76,
  },
  {
    source: 'static/tasks/hero-task-campus-v09-final.png',
    target: 'static/tasks/hero-task-campus-v09-final.jpg',
    maxWidth: 960,
    quality: 76,
  },
]

const textExtensions = new Set(['.js', '.json', '.wxml', '.wxss'])

async function convertAsset(asset) {
  const sourcePath = path.join(buildRoot, asset.source)
  const targetPath = path.join(buildRoot, asset.target)
  const sourceStat = await fs.stat(sourcePath)
  const image = await Jimp.read(sourcePath)

  if (image.bitmap.width > asset.maxWidth) {
    image.resize(asset.maxWidth, Jimp.AUTO)
  }

  image.quality(asset.quality)
  await image.writeAsync(targetPath)
  await fs.unlink(sourcePath)

  const targetStat = await fs.stat(targetPath)
  return {
    source: asset.source,
    target: asset.target,
    beforeBytes: sourceStat.size,
    afterBytes: targetStat.size,
  }
}

async function rewriteReferences(directory) {
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    const entryPath = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      await rewriteReferences(entryPath)
      continue
    }
    if (!textExtensions.has(path.extname(entry.name))) continue

    const original = await fs.readFile(entryPath, 'utf8')
    let updated = original
    for (const asset of assets) {
      updated = updated.replaceAll(asset.source, asset.target)
    }
    if (updated !== original) {
      await fs.writeFile(entryPath, updated)
    }
  }
}

async function main() {
  await fs.access(path.join(buildRoot, 'app.json'))
  const results = []
  for (const asset of assets) {
    results.push(await convertAsset(asset))
  }
  await rewriteReferences(buildRoot)

  for (const result of results) {
    const before = Math.round(result.beforeBytes / 1024)
    const after = Math.round(result.afterBytes / 1024)
    console.log(`Optimized ${result.source} -> ${result.target}: ${before} KB -> ${after} KB`)
  }
}

main().catch((error) => {
  console.error(error)
  process.exitCode = 1
})
