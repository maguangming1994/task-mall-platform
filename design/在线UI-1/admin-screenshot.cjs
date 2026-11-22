// 管理后台 PC 设计图批量截图（1440×900）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const dir = __dirname;
const file = path.join(dir, 'admin-screens.html');
const out = path.join(dir, 'images');

function resolveChromium() {
  const base = process.env.LOCALAPPDATA;
  if (!base) return undefined;
  const root = path.join(base, 'ms-playwright');
  if (!fs.existsSync(root)) return undefined;
  const dirs = fs.readdirSync(root).filter(d => /^chromium-\d+$/.test(d)).sort().reverse();
  for (const d of dirs) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
  return undefined;
}

const jobs = [
  { screen: 'admin-login',       name: 'a01-login' },
  { screen: 'admin-dashboard',   name: 'a02-dashboard' },
  { screen: 'admin-task-audit',  name: 'a03-task-audit' },
  { screen: 'admin-sh-audit',    name: 'a04-sh-audit' },
  { screen: 'admin-product',     name: 'a05-product' },
  { screen: 'admin-order',       name: 'a06-order' },
  { screen: 'admin-aftersale',   name: 'a07-aftersale' },
  { screen: 'admin-withdraw',    name: 'a08-withdraw' },
  { screen: 'admin-user',        name: 'a09-user' },
  { screen: 'admin-community',   name: 'a10-community' },
  { screen: 'admin-support',     name: 'a11-support' },
  { screen: 'admin-operations',  name: 'a12-operations' },
  { screen: 'admin-config',      name: 'a13-config' },
  { screen: 'admin-permissions', name: 'a14-permissions' },
  { screen: 'admin-task-list',   name: 'a15-task-list' },
  { screen: 'admin-task-template', name: 'a16-task-template' },
  { screen: 'admin-category',    name: 'a17-category' },
  { screen: 'admin-inventory',   name: 'a18-inventory' },
  { screen: 'admin-points',      name: 'a19-points' },
  { screen: 'admin-coupon',      name: 'a20-coupon' },
  { screen: 'admin-reconcile',   name: 'a21-reconcile' },
  { screen: 'admin-report',      name: 'a22-report' },
  { screen: 'admin-notify',      name: 'a23-notify' },
  { screen: 'admin-data',        name: 'a24-data' },
  { screen: 'admin-campus',      name: 'a25-campus' },
  { screen: 'admin-auditlog',    name: 'a26-auditlog' },
  { screen: 'admin-material-audit',    name: 'a27-material-audit' },
  { screen: 'admin-material-category', name: 'a28-material-category' },
  { screen: 'admin-material-request',  name: 'a29-material-request' },
  { screen: 'admin-pt-review',         name: 'a30-pt-review' },
  { screen: 'admin-pt-teams',          name: 'a31-pt-teams' },
  { screen: 'admin-pt-members',        name: 'a32-pt-members' },
  { screen: 'admin-pt-rules',          name: 'a33-pt-rules' },
  { screen: 'admin-pt-teams-error',    name: 'a34-pt-teams-error' },
  { screen: 'admin-pt-task-config',    name: 'a35-pt-task-config' },
  { screen: 'admin-pt-batches',        name: 'a36-pt-batches' },
  { screen: 'admin-pt-batches-empty',  name: 'a37-pt-batches-empty' },
  { screen: 'ci-a1',                   name: 'a38-ci-a1' },
  { screen: 'ci-a2',                   name: 'a39-ci-a2' },
  { screen: 'ci-a3',                   name: 'a40-ci-a3' },
  { screen: 'ci-a4',                   name: 'a41-ci-a4' },
  { screen: 'admin-poster',            name: 'a42-poster' },
];

(async () => {
  const executablePath = resolveChromium();
  const browser = await chromium.launch(executablePath ? { executablePath } : {});
  const only = process.argv[2];
  for (const j of jobs) {
    if (only && j.name !== only) continue;
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    await page.goto('file:///' + file.replace(/\\/g, '/'), { waitUntil: 'load', timeout: 60000 });
    await page.evaluate((sc) => {
      document.body.classList.add('shot');
      document.body.setAttribute('data-screen', sc);
      document.querySelectorAll('.screen.on').forEach((s) => s.classList.remove('on'));
      const el = document.querySelector('.screen[data-screen="' + sc + '"]');
      if (el) {
        el.classList.add('on');
        if (getComputedStyle(el).display === 'none') {
          document.body.removeAttribute('data-screen');
        }
      }
    }, j.screen);
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(out, j.name + '.png'), clip: { x: 0, y: 0, width: 1440, height: 900 } });
    await page.close();
    console.log('done:', j.name);
  }
  await browser.close();
})();
