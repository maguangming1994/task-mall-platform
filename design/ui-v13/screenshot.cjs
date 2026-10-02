const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const screens = ['home', 'tasks', 'mall', 'community', 'account'];
  const only = process.argv[2];
  const list = only ? [only] : screens;
  const htmlPath = path.join(__dirname, 'redesign.html');
  const browser = await chromium.launch();
  for (const s of list) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await page.goto('file:///' + htmlPath.replace(/\\/g, '/'), { waitUntil: 'load', timeout: 60000 });
    await page.evaluate((sc) => {
      document.body.classList.add('shot');
      document.body.setAttribute('data-screen', sc);
    }, s);
    try {
      await page.waitForFunction(() => Array.from(document.images).every(i => i.complete), null, { timeout: 45000 });
    } catch (e) { console.log('img wait timeout:', s); }
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(__dirname, s + '.png'), clip: { x: 0, y: 0, width: 390, height: 844 } });
    await page.close();
    console.log('done:', s);
  }
  await browser.close();
})();
