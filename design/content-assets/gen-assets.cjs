// 将 gen.html 内的原创扁平插画光栅化为 PNG，输出到 apps/api/public/content
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const dir = __dirname;
const outRoot = path.join(dir, '..', '..', 'apps', 'api', 'public', 'content');

const jobs = [
  { id: 'promo-group-buy', dir: 'home' },
  { id: 'errand-courier', dir: 'tasks' },
  { id: 'study-print', dir: 'tasks' },
  { id: 'study-buddy', dir: 'tasks' },
  { id: 'errand-meal', dir: 'tasks' },
  { id: 'lost-keys', dir: 'tasks' },
  { id: 'skill-repair', dir: 'tasks' },
  { id: 'research-survey', dir: 'tasks' },
  { id: 'errand-delivery', dir: 'tasks' },
  { id: 'meal-ramen', dir: 'feed' },
  { id: 'meal-tea', dir: 'feed' },
  { id: 'meal-salad', dir: 'feed' },
  { id: 'student-girl', dir: 'personas' },
  { id: 'student-boy-blue', dir: 'personas' },
  { id: 'student-boy-orange', dir: 'personas' },
];

function resolveChromium() {
  const base = process.env.LOCALAPPDATA;
  if (!base) return undefined;
  const root = path.join(base, 'ms-playwright');
  if (!fs.existsSync(root)) return undefined;
  for (const d of fs.readdirSync(root).filter(d => /^chromium-\d+$/.test(d)).sort().reverse()) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
  return undefined;
}

(async () => {
  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: 1 });
  await page.goto('file:///' + path.join(dir, 'gen.html').replace(/\\/g, '/'), { waitUntil: 'load' });
  for (const job of jobs) {
    const target = path.join(outRoot, job.dir);
    fs.mkdirSync(target, { recursive: true });
    const handle = await page.$('#' + job.id);
    await handle.screenshot({ path: path.join(target, job.id + '.png') });
    console.log('done', job.dir + '/' + job.id + '.png');
  }
  await browser.close();
})();
