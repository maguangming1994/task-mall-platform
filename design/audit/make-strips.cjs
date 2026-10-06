// 生成 设计稿|实际页 对照拼图（分批全页截图，供逐屏视觉比对）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const ROOT = __dirname;
const DESIGN = path.join(ROOT, '..', '在线UI-1', 'images');
const ACT_U = path.join(ROOT, 'actual', 'user');
const ACT_A = path.join(ROOT, 'actual', 'admin');
const OUT = path.join(ROOT, 'strips');
fs.mkdirSync(OUT, { recursive: true });
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'manifest.json'), 'utf8'));

function resolveChromium() {
  const root = path.join(process.env.LOCALAPPDATA, 'ms-playwright');
  if (!fs.existsSync(root)) return undefined;
  for (const d of fs.readdirSync(root).filter(x => /^chromium-\d+$/.test(x)).sort().reverse()) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
  return undefined;
}
const fileUrl = p => 'file:///' + encodeURI(path.resolve(p).replace(/\\/g, '/'));

function pairHtml(item, isAdmin) {
  const [id, name, route] = item;
  let designImg = path.join(DESIGN, id + '.png');
  let actualImg;
  if (typeof route === 'string' && route.startsWith('ADMIN:')) {
    actualImg = path.join(ACT_A, route.split(':')[1] + '.png');
  } else {
    actualImg = path.join(isAdmin ? ACT_A : ACT_U, id + '.png');
  }
  const w = isAdmin ? 690 : 384;
  return `<div class="pair">
    <div class="bar"><b>${id}</b>&nbsp;${name}<span class="rt">${route}</span></div>
    <div class="cols">
      <div class="col"><div class="tag tag-d">设计稿</div><img style="width:${w}px" src="${fileUrl(designImg)}"></div>
      <div class="col"><div class="tag tag-a">实际页面</div><img style="width:${w}px" src="${fileUrl(actualImg)}"></div>
    </div>
  </div>`;
}

function pageHtml(pairs, isAdmin) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>
  *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI','Microsoft YaHei',sans-serif}
  body{background:#888;padding:10px}
  .pair{background:#fff;margin-bottom:14px;border-radius:6px;overflow:hidden}
  .bar{background:#1f2937;color:#fff;padding:8px 14px;font-size:18px}
  .bar .rt{float:right;color:#9ca3af;font-size:13px;font-weight:normal}
  .cols{display:flex;gap:10px;padding:10px;align-items:flex-start}
  .col{flex:none}
  .tag{display:inline-block;padding:3px 10px;border-radius:4px;color:#fff;font-size:13px;margin-bottom:6px}
  .tag-d{background:#2563eb}.tag-a{background:#16a34a}
  img{display:block;border:1px solid #e5e7eb;border-radius:4px}
  </style></head><body>${pairs.map(p => pairHtml(p, isAdmin)).join('')}</body></html>`;
}

(async () => {
  const browser = await chromium.launch({ executablePath: resolveChromium() });
  async function shoot(html, file) {
    const tmp = path.join(OUT, '_tmp.html');
    fs.writeFileSync(tmp, html, 'utf8');
    const page = await browser.newPage({ viewport: { width: 830, height: 900 }, deviceScaleFactor: 1.5 });
    await page.goto(fileUrl(tmp), { waitUntil: 'networkidle' });
    await page.screenshot({ path: file, fullPage: true });
    await page.close();
  }
  // 用户端：3 屏/条；38(ADMIN映射) 随用户清单一起渲染
  let idx = 0;
  for (let i = 0; i < manifest.user.length; i += 3) {
    const chunk = manifest.user.slice(i, i + 3);
    await shoot(pageHtml(chunk, false), path.join(OUT, `u-${String(++idx).padStart(2, '0')}.png`));
  }
  // 后台：2 屏/条
  idx = 0;
  for (let i = 0; i < manifest.admin.length; i += 2) {
    const chunk = manifest.admin.slice(i, i + 2);
    const tmp = path.join(OUT, '_tmp.html');
    fs.writeFileSync(tmp, pageHtml(chunk, true), 'utf8');
    const page = await browser.newPage({ viewport: { width: 1430, height: 900 }, deviceScaleFactor: 1 });
    await page.goto(fileUrl(tmp), { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(OUT, `a-${String(++idx).padStart(2, '0')}.png`), fullPage: true });
    await page.close();
  }
  try { fs.unlinkSync(path.join(OUT, '_tmp.html')); } catch {}
  await browser.close();
  console.log('strips done');
})();
