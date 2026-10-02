const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const screens = ['home', 'tasks', 'mall', 'community', 'account'];
  const htmlPath = path.join(__dirname, 'redesign.html');
  const browser = await chromium.launch();
  for (const s of screens) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await page.goto('file:///' + htmlPath.replace(/\\/g, '/'));
    await page.evaluate((sc) => { document.body.setAttribute('data-screen', sc); }, s);
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(__dirname, s + '.png'), clip: { x: 0, y: 0, width: 390, height: 844 } });
    await page.close();
    console.log('done:', s);
  }
  await browser.close();
})();
