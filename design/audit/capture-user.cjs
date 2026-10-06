// 用户端 57 屏实际页面截图（H5 dev: http://127.0.0.1:5180，登录演示用户 13800000001）
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const BASE = 'http://127.0.0.1:5180';
const API = 'http://127.0.0.1:8000/api/v1';
const OUT = path.join(__dirname, 'actual', 'user');
fs.mkdirSync(OUT, { recursive: true });

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

const CLIENT_HEADERS = { 'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'audit-capture-001', 'X-Device-Name': 'Playwright Audit' };
async function apiPost(p, body, token) {
  const res = await fetch(API + p, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...CLIENT_HEADERS, ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: JSON.stringify(body),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${p} -> ${res.status} ${JSON.stringify(json).slice(0, 300)}`);
  return json.data ?? json;
}
async function apiGet(p, token) {
  const res = await fetch(API + p, { headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${p} -> ${res.status}`);
  return json.data ?? json;
}
const firstId = (data) => {
  const item = data?.items?.[0] ?? data?.[0] ?? data?.data?.[0];
  return item?.id ?? item?.public_id ?? '';
};

(async () => {
  // 1. API 登录拿 token 与真实业务 ID
  await apiPost('/auth/phone/verification-codes', { phone: '13800000001', purpose: 'login' });
  const auth = await apiPost('/auth/phone/login', { phone: '13800000001', verification_code: '246810' });
  const token = auth.access_token;
  const [tasks, products, posts, shItems, activities, orders] = await Promise.all([
    apiGet('/tasks?per_page=5', token).catch(() => ({})),
    apiGet('/products?per_page=5').catch(() => ({})),
    apiGet('/community/posts?per_page=5').catch(() => ({})),
    apiGet('/second-hand/items?per_page=5').catch(() => ({})),
    apiGet('/community/activities?per_page=5', token).catch(() => ({})),
    apiGet('/orders?per_page=5', token).catch(() => ({})),
  ]);
  const ids = {
    task: firstId(tasks), product: firstId(products), post: firstId(posts),
    sh: firstId(shItems), act: firstId(activities), order: firstId(orders),
    seller: shItems?.items?.[0]?.seller?.id ?? '',
    conv: '', shOrder: '',
  };
  console.log('ids:', JSON.stringify(ids));
  const q = (k, v) => (v ? `?${k}=${encodeURIComponent(v)}` : '');

  const jobs = [
    ['01-home', '/pages/index/index'],
    ['02-tasks', '/pages/tasks/index'],
    ['03-mall', '/pages/products/index'],
    ['04-community', '/pages/community/index'],
    ['05-account', '/pages/account/index'],
    ['06-login', '/pages/login/index'],
    ['07-search', '/pages/search/index'],
    ['08-task-detail', `/pages/task-detail/index${q('id', ids.task)}`],
    ['09-task-publish', '/pages/publisher/index'],
    ['10-task-confirm', `/pages/task-claim/index${q('id', ids.task)}`],
    ['11-goods-detail', `/pages/product-detail/index${q('id', ids.product)}`],
    ['12-exchange', '/pages/checkout/index'],
    ['13-exchange-success', '/pages/payment-result/index?status=success'],
    ['14-orders', '/pages/orders/index'],
    ['15-post-detail', `/pages/community-detail/index${q('id', ids.post)}`],
    ['16-post-publish', '/pages/community-compose/index'],
    ['17-signin', '/pages/check-in/index'],
    ['18-points', '/pages/assets/index'],
    ['19-coupons', '/pages/coupons/index'],
    ['20-messages', '/pages/notifications/index'],
    ['21-sh-market', '/pages/second-hand/index'],
    ['22-sh-publish', '/pages/second-hand-publish/index'],
    ['23-sh-detail', `/pages/second-hand-detail/index${q('id', ids.sh)}`],
    ['24-sh-buy', `/pages/second-hand-buy/index${q('id', ids.sh)}`],
    ['25-sh-orders', '/pages/second-hand-orders/index'],
    ['26-chat-list', '/pages/chat/index'],
    ['27-chat', `/pages/chat-detail/index${q('id', ids.conv)}`],
    ['28-sh-my-list', '/pages/second-hand-my/index'],
    ['29-sh-order-detail', `/pages/second-hand-order-detail/index${q('id', ids.shOrder)}`],
    ['30-sh-rate', `/pages/second-hand-rate/index${q('id', ids.shOrder)}`],
    ['31-sh-ship', `/pages/second-hand-ship/index${q('id', ids.shOrder)}`],
    ['32-sh-refund', '/pages/second-hand-refund/index'],
    ['33-sh-user', `/pages/sh-user/index${q('id', ids.seller)}`],
    ['34-wallet', '/pages/assets/index?tab=wallet'],
    ['35-recharge', '/pages/wallet-recharge/index'],
    ['36-withdraw', '/pages/withdraw/index'],
    ['37-address', '/pages/addresses/index'],
    ['38-admin-audit', null],
    ['39-as-list', '/pages/after-sales/index'],
    ['40-as-apply', '/pages/after-sale-apply/index'],
    ['41-as-detail', '/pages/after-sale-detail/index'],
    ['42-act-list', '/pages/community-activities/index'],
    ['43-act-detail', `/pages/community-activity-detail/index${q('id', ids.act)}`],
    ['44-act-compose', '/pages/community-activity-compose/index'],
    ['45-legal-consent', '/pages/legal-consent/index'],
    ['46-privacy', '/pages/privacy/index'],
    ['47-realname', '/pages/identity-verification/index'],
    ['48-referrals', '/pages/referrals/index'],
    ['49-ref-poster', '/pages/referral-poster/index'],
    ['50-cart', '/pages/cart/index'],
    ['51-support', '/pages/support/index'],
    ['52-pub-detail', `/pages/publisher-task-detail/index${q('id', ids.task)}`],
    ['53-bank-cards', '/pages/bank-cards/index'],
    ['54-bank-card-add', '/pages/bank-card-add/index'],
    ['55-order-detail', `/pages/order-detail/index${q('id', ids.order)}`],
    ['56-coupon-center', '/pages/coupon-center/index'],
    ['57-legal', '/pages/legal/index'],
  ];

  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  // 预置登录态（uni H5 用 localStorage 持久化 task_mall_auth）
  await page.goto(BASE + '/#/pages/login/index', { waitUntil: 'load' });
  await page.evaluate((a) => {
    localStorage.setItem('task_mall_auth', JSON.stringify({
      accessToken: a.access_token, refreshToken: a.refresh_token,
      expiresAt: Date.now() + a.expires_in * 1000, user: a.user,
    }));
  }, auth);

  for (const [name, route] of jobs) {
    if (!route) { console.log('skip:', name); continue; }
    try {
      await page.goto(BASE + '/#' + route, { waitUntil: 'load', timeout: 30000 });
      await page.waitForTimeout(1800);
      await page.screenshot({ path: path.join(OUT, name + '.png'), clip: { x: 0, y: 0, width: 390, height: 844 } });
      console.log('done:', name);
    } catch (e) {
      console.log('FAIL:', name, e.message.slice(0, 160));
    }
  }
  await browser.close();
})();
