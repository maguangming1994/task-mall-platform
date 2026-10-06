// 补截 a14-permissions（独立上下文）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');
const BASE = 'http://127.0.0.1:5174';
const OUT = path.join(__dirname, 'actual', 'admin');
function resolveChromium() {
  const root = path.join(process.env.LOCALAPPDATA, 'ms-playwright');
  for (const d of fs.readdirSync(root).filter(x => /^chromium-\d+$/.test(x)).sort().reverse()) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
}
(async () => {
  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.fill('input[autocomplete="username"]', 'preview_admin');
  await page.fill('input[autocomplete="current-password"]', 'TaskMall2026!Preview');
  await page.click('button:has-text("安全登录")');
  await page.waitForTimeout(3500);
  const sec = page.locator('.ant-menu-item:has-text("权限与审计")');
  await sec.evaluate((el) => { el.closest('.ant-menu')?.scrollTo?.(0, el.offsetTop); });
  await page.waitForTimeout(300);
  await sec.evaluate((el) => el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })));
  await page.waitForTimeout(2200);
  await page.screenshot({ path: path.join(OUT, 'a14-permissions.png'), clip: { x: 0, y: 0, width: 1440, height: 900 } });
  console.log('done: a14-permissions');
  await browser.close();
})();
