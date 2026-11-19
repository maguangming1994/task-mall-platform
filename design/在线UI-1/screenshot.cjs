// 批量渲染页面设计图：前 5 屏取自 v13，其余 52 屏取自 sub-screens.html（共 57 屏）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const dir = __dirname;
const v13 = path.join(dir, '..', 'ui-v13', 'redesign.html');
const sub = path.join(dir, 'sub-screens.html');
const out = path.join(dir, 'images');

// 优先使用本机已缓存的 Playwright Chromium，避免重复下载
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
  { file: v13,  screen: 'home',             name: '01-home' },
  { file: v13,  screen: 'tasks',            name: '02-tasks' },
  { file: v13,  screen: 'mall',             name: '03-mall' },
  { file: v13,  screen: 'community',        name: '04-community' },
  { file: v13,  screen: 'account',          name: '05-account' },
  { file: sub,  screen: 'login',            name: '06-login' },
  { file: sub,  screen: 'search',           name: '07-search' },
  { file: sub,  screen: 'task-detail',      name: '08-task-detail' },
  { file: sub,  screen: 'task-publish',     name: '09-task-publish' },
  { file: sub,  screen: 'task-confirm',     name: '10-task-confirm' },
  { file: sub,  screen: 'goods-detail',     name: '11-goods-detail' },
  { file: sub,  screen: 'exchange',         name: '12-exchange' },
  { file: sub,  screen: 'exchange-success', name: '13-exchange-success' },
  { file: sub,  screen: 'orders',           name: '14-orders' },
  { file: sub,  screen: 'post-detail',      name: '15-post-detail' },
  { file: sub,  screen: 'post-publish',     name: '16-post-publish' },
  { file: sub,  screen: 'signin',           name: '17-signin' },
  { file: sub,  screen: 'points',           name: '18-points' },
  { file: sub,  screen: 'coupons',          name: '19-coupons' },
  { file: sub,  screen: 'messages',         name: '20-messages' },
  { file: sub,  screen: 'sh-market',       name: '21-sh-market' },
  { file: sub,  screen: 'sh-publish',      name: '22-sh-publish' },
  { file: sub,  screen: 'sh-detail',       name: '23-sh-detail' },
  { file: sub,  screen: 'sh-buy',           name: '24-sh-buy' },
  { file: sub,  screen: 'sh-orders',        name: '25-sh-orders' },
  { file: sub,  screen: 'chat-list',       name: '26-chat-list' },
  { file: sub,  screen: 'chat',            name: '27-chat' },
  { file: sub,  screen: 'sh-my-list',      name: '28-sh-my-list' },
  { file: sub,  screen: 'sh-order-detail', name: '29-sh-order-detail' },
  { file: sub,  screen: 'sh-rate',         name: '30-sh-rate' },
  { file: sub,  screen: 'sh-ship',         name: '31-sh-ship' },
  { file: sub,  screen: 'sh-refund',       name: '32-sh-refund' },
  { file: sub,  screen: 'sh-user',         name: '33-sh-user' },
  { file: sub,  screen: 'wallet',          name: '34-wallet' },
  { file: sub,  screen: 'recharge',        name: '35-recharge' },
  { file: sub,  screen: 'withdraw',        name: '36-withdraw' },
  { file: sub,  screen: 'address',         name: '37-address' },
  { file: sub,  screen: 'admin-audit',     name: '38-admin-audit' },
  { file: sub,  screen: 'as-list',         name: '39-as-list' },
  { file: sub,  screen: 'as-apply',        name: '40-as-apply' },
  { file: sub,  screen: 'as-detail',       name: '41-as-detail' },
  { file: sub,  screen: 'act-list',        name: '42-act-list' },
  { file: sub,  screen: 'act-detail',      name: '43-act-detail' },
  { file: sub,  screen: 'act-compose',     name: '44-act-compose' },
  { file: sub,  screen: 'legal-consent',   name: '45-legal-consent' },
  { file: sub,  screen: 'privacy',         name: '46-privacy' },
  { file: sub,  screen: 'realname',        name: '47-realname' },
  { file: sub,  screen: 'referrals',       name: '48-referrals' },
  { file: sub,  screen: 'ref-poster',      name: '49-ref-poster' },
  { file: sub,  screen: 'cart',            name: '50-cart' },
  { file: sub,  screen: 'support',         name: '51-support' },
  { file: sub,  screen: 'pub-detail',      name: '52-pub-detail' },
  { file: sub,  screen: 'bank-cards',      name: '53-bank-cards' },
  { file: sub,  screen: 'bank-card-add',   name: '54-bank-card-add' },
  { file: sub,  screen: 'order-detail',    name: '55-order-detail' },
  { file: sub,  screen: 'coupon-center',   name: '56-coupon-center' },
  { file: sub,  screen: 'legal-docs',      name: '57-legal' },
  { file: sub,  screen: 'mt-list',         name: '58-material-list' },
  { file: sub,  screen: 'mt-detail',       name: '59-material-detail' },
  { file: sub,  screen: 'mt-post',         name: '60-material-post' },
  { file: sub,  screen: 'mt-request',      name: '61-material-request' },
  { file: sub,  screen: 'pt-center',        name: '62-push-center' },
  { file: sub,  screen: 'pt-teams',         name: '63-push-teams' },
  { file: sub,  screen: 'pt-teams-empty',   name: '64-push-teams-empty' },
  { file: sub,  screen: 'pt-team-detail',   name: '65-push-team-detail' },
  { file: sub,  screen: 'pt-my-team',       name: '66-push-my-team' },
  { file: sub,  screen: 'pt-team-admin',    name: '67-push-team-admin' },
  { file: sub,  screen: 'pt-tasks',         name: '68-push-tasks' },
  { file: sub,  screen: 'pt-task-detail',   name: '69-push-task-detail' },
  { file: sub,  screen: 'pt-positions',     name: '70-push-positions' },
  { file: sub,  screen: 'pt-submit',        name: '71-push-submit' },
  { file: sub,  screen: 'pt-submit-error',  name: '72-push-submit-error' },
  { file: sub,  screen: 'pt-earnings',      name: '73-push-earnings' },
  { file: sub,  screen: 'ci-m1',             name: '74-ci-m1' },
  { file: sub,  screen: 'ci-m1b',            name: '75-ci-m1b' },
  { file: sub,  screen: 'ci-m1c',            name: '76-ci-m1c' },
  { file: sub,  screen: 'ci-m2',             name: '77-ci-m2' },
  { file: sub,  screen: 'ci-m3',             name: '78-ci-m3' },
  { file: sub,  screen: 'ci-m7',             name: '79-ci-m7' },
  { file: sub,  screen: 'ci-m8',             name: '80-ci-m8' },
  { file: sub,  screen: 'ci-m4',             name: '81-ci-m4' },
  { file: sub,  screen: 'ci-m9',             name: '82-ci-m9' },
  { file: sub,  screen: 'ci-m5',             name: '83-ci-m5' },
  { file: sub,  screen: 'ci-m6',             name: '84-ci-m6' },
  { file: sub,  screen: 'mt-fetches',        name: '85-material-fetches' },
];

(async () => {
  const executablePath = resolveChromium();
  const browser = await chromium.launch(executablePath ? { executablePath } : {});
  const only = process.argv[2];
  for (const j of jobs) {
    if (only && j.name !== only) continue;
    if (!fs.existsSync(j.file)) {
      console.log('skip (source missing):', j.name);
      continue;
    }
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await page.goto('file:///' + j.file.replace(/\\/g, '/'), { waitUntil: 'load', timeout: 60000 });
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
    try {
      await page.waitForFunction(() => Array.from(document.images).every(i => i.complete), null, { timeout: 45000 });
    } catch (e) { console.log('img wait timeout:', j.name); }
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(out, j.name + '.png'), clip: { x: 0, y: 0, width: 390, height: 844 } });
    await page.close();
    console.log('done:', j.name);
  }
  await browser.close();
})();
