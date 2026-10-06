// 管理后台 26 屏实际页面截图（http://127.0.0.1:5174，preview_admin 本地账号，hash 路由）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const BASE = 'http://127.0.0.1:5174';
const OUT = path.join(__dirname, 'actual', 'admin');
fs.mkdirSync(OUT, { recursive: true });

function resolveChromium() {
  const root = path.join(process.env.LOCALAPPDATA, 'ms-playwright');
  for (const d of fs.readdirSync(root).filter(x => /^chromium-\d+$/.test(x)).sort().reverse()) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
}

// 名称 -> hash 路由
const jobs = [
  ['a01-login', null],
  ['a02-dashboard', '/workbench'],
  ['a03-task-audit', '/tasks/audit'],
  ['a04-sh-audit', '/mall/second-hand'],
  ['a05-product', '/mall/products'],
  ['a06-order', '/orders'],
  ['a07-aftersale', '/after-sales'],
  ['a08-withdraw', '/finance/withdrawals'],
  ['a09-user', '/users'],
  ['a10-community', '/community'],
  ['a11-support', '/support/tickets'],
  ['a12-operations', '/operations/campaigns'],
  ['a13-config', '/config'],
  ['a14-permissions', '/security/roles'],
  ['a15-task-list', '/tasks/list'],
  ['a16-task-template', '/tasks/templates'],
  ['a17-category', '/mall/categories'],
  ['a18-inventory', '/mall/inventory'],
  ['a19-points', '/finance/points'],
  ['a20-coupon', '/mall/coupons'],
  ['a21-reconcile', '/finance/reconcile'],
  ['a22-report', '/community/reports'],
  ['a23-notify', '/operations/notify'],
  ['a24-data', '/operations/data-board'],
  ['a25-campus', '/security/campuses'],
  ['a26-auditlog', '/security/logs'],
];

// 可通过命令行参数只截部分页，例如: node capture-admin.cjs a01-login a02-dashboard
const only = process.argv.slice(2);

(async () => {
  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  page.on('console', () => {});
  page.on('pageerror', () => {});

  await page.goto(BASE, { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(800);

  if (!only.length || only.includes('a01-login')) {
    await page.screenshot({ path: path.join(OUT, 'a01-login.png'), clip: { x: 0, y: 0, width: 1440, height: 900 } });
    console.log('done: a01-login');
  }

  // 登录
  await page.fill('input[autocomplete="username"]', 'preview_admin');
  await page.fill('input[autocomplete="current-password"]', 'TaskMall2026!Preview');
  await page.click('button:has-text("登")');
  await page.waitForTimeout(4000);

  for (const [name, route] of jobs.slice(1)) {
    if (only.length && !only.includes(name)) continue;
    try {
      await page.goto(BASE + '/#' + route, { waitUntil: 'networkidle', timeout: 20000 });
      await page.waitForTimeout(1600);
      await page.screenshot({ path: path.join(OUT, name + '.png'), clip: { x: 0, y: 0, width: 1440, height: 900 } });
      console.log('done:', name);
    } catch (e) {
      console.log('FAIL:', name, e.message.slice(0, 160));
    }
  }
  await browser.close();
})();
