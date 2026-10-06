// 补截：addInitScript 预置登录态后重截需鉴权页面 + 01 关闭新人弹窗 + 04 登录态社区
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const BASE = 'http://127.0.0.1:5180';
const API = 'http://127.0.0.1:8000/api/v1';
const OUT = path.join(__dirname, 'actual', 'user');

function resolveChromium() {
  const root = path.join(process.env.LOCALAPPDATA, 'ms-playwright');
  for (const d of fs.readdirSync(root).filter(x => /^chromium-\d+$/.test(x)).sort().reverse()) {
    for (const p of ['chrome-win64\\chrome.exe', 'chrome-win\\chrome.exe']) {
      const exe = path.join(root, d, p);
      if (fs.existsSync(exe)) return exe;
    }
  }
}
const H = { 'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'audit-recapture-002', 'X-Device-Name': 'Playwright Audit' };
async function apiPost(p, body, token) {
  const res = await fetch(API + p, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...H, ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: JSON.stringify(body) });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${p} -> ${res.status} ${JSON.stringify(json).slice(0, 200)}`);
  return json.data ?? json;
}
async function apiGet(p, token) {
  const res = await fetch(API + p, { headers: { Accept: 'application/json', ...H, ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) return { __error: res.status };
  return json.data ?? json;
}
const firstId = (data) => data?.items?.[0]?.id ?? data?.[0]?.id ?? data?.data?.[0]?.id ?? '';

(async () => {
  await apiPost('/auth/phone/verification-codes', { phone: '13800000001', purpose: 'login' });
  const auth = await apiPost('/auth/phone/login', { phone: '13800000001', verification_code: '246810' });
  const token = auth.access_token;
  console.log('user keys:', Object.keys(auth.user || {}).join(','), 'phone_bound=', auth.user?.phone_bound, 'expires_in=', auth.expires_in);
  const [tasks, products, posts, shItems, activities, orders, convs, shOrders] = await Promise.all([
    apiGet('/tasks?per_page=5', token), apiGet('/products?per_page=5'),
    apiGet('/community/posts?per_page=5', token), apiGet('/second-hand/items?per_page=5'),
    apiGet('/community/activities?per_page=5', token), apiGet('/orders?per_page=5', token),
    apiGet('/conversations?per_page=5', token), apiGet('/second-hand/orders?per_page=5', token),
  ]);
  console.log('posts status:', posts.__error || 'ok', 'convs:', convs.__error || (convs.items?.length ?? 0), 'shOrders:', shOrders.__error || (shOrders.items?.length ?? 0));
  const ids = {
    task: firstId(tasks), product: firstId(products), post: firstId(posts),
    sh: firstId(shItems), act: firstId(activities), order: firstId(orders),
    seller: shItems?.items?.[0]?.seller?.id ?? '',
  };
  console.log('ids:', JSON.stringify(ids));
  // 接受全部待确认协议，避免 account 等页被协议确认拦截
  const pending = await apiGet('/me/legal-consents/pending', token);
  if (Array.isArray(pending)) {
    for (const d of pending) {
      const did = d.id ?? d.version_id ?? d.legal_document_version_id;
      if (!did) continue;
      const rr = await fetch(API + '/me/legal-consents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...H, Authorization: `Bearer ${token}`, 'Idempotency-Key': `audit-legal-${did}` },
        body: JSON.stringify({ legal_document_version_id: did, action: 'accept' }),
      });
      console.log('accept legal', did, rr.status);
    }
  }
  const q = (k, v) => (v ? `?${k}=${encodeURIComponent(v)}` : '');

  const authPayload = {
    accessToken: auth.access_token, refreshToken: auth.refresh_token,
    expiresAt: Date.now() + (auth.expires_in || 3600) * 1000, user: auth.user,
  };

  const jobs = [
    ['01-home', '/pages/index/index', true],
    ['04-community', '/pages/community/index'],
    ['05-account', '/pages/account/index'],
    ['08-task-detail', `/pages/task-detail/index${q('id', ids.task)}`],
    ['09-task-publish', '/pages/publisher/index'],
    ['10-task-confirm', `/pages/task-claim/index${q('id', ids.task)}`],
    ['12-exchange', `/pages/checkout/index${q('product_id', ids.product)}`],
    ['14-orders', '/pages/orders/index'],
    ['15-post-detail', `/pages/community-detail/index${q('id', ids.post || '01M44PHGNXZ3WTDDXVDP4QCECF')}`],
    ['16-post-publish', '/pages/community-compose/index'],
    ['17-signin', '/pages/check-in/index'],
    ['18-points', '/pages/assets/index'],
    ['19-coupons', '/pages/coupons/index'],
    ['20-messages', '/pages/notifications/index'],
    ['24-sh-buy', `/pages/second-hand-buy/index${q('id', ids.sh)}`],
    ['33-sh-user', `/pages/sh-user/index${q('id', ids.seller)}`],
    ['34-wallet', '/pages/assets/index?tab=wallet'],
    ['35-recharge', '/pages/wallet-recharge/index'],
    ['36-withdraw', '/pages/withdraw/index'],
    ['37-address', '/pages/addresses/index'],
    ['39-as-list', '/pages/after-sales/index'],
    ['40-as-apply', '/pages/after-sale-apply/index'],
    ['41-as-detail', '/pages/after-sale-detail/index'],
    ['44-act-compose', '/pages/community-activity-compose/index'],
    ['45-legal-consent', '/pages/legal-consent/index'],
    ['46-privacy', '/pages/privacy/index'],
    ['47-realname', '/pages/identity-verification/index'],
    ['48-referrals', '/pages/referrals/index'],
    ['49-ref-poster', '/pages/referral-poster/index'],
    ['50-cart', '/pages/cart/index'],
    ['52-pub-detail', `/pages/publisher-task-detail/index${q('id', ids.task)}`],
    ['53-bank-cards', '/pages/bank-cards/index'],
    ['55-order-detail', `/pages/order-detail/index${q('id', ids.order)}`],
    ['56-coupon-center', '/pages/coupon-center/index'],
  ];

  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  await context.addInitScript((p) => {
    // uni-app H5 存储格式：{type:'object',data:<obj>}，裸 JSON 会被 parseValue 判为无效
    try { localStorage.setItem('task_mall_auth', JSON.stringify({ type: 'object', data: p })); } catch {}
  }, authPayload);
  const page = await context.newPage();

  for (const [name, route, dismissPopup] of jobs) {
    try {
      await page.goto(BASE + '/#' + route, { waitUntil: 'load', timeout: 30000 });
      await page.waitForTimeout(2200);
      if (dismissPopup) {
        const btn = await page.$('text=我知道了').catch(() => null);
        if (btn) { await btn.click().catch(() => {}); await page.waitForTimeout(1200); }
      }
      await page.screenshot({ path: path.join(OUT, name + '.png'), clip: { x: 0, y: 0, width: 390, height: 844 } });
      console.log('done:', name);
    } catch (e) {
      console.log('FAIL:', name, e.message.slice(0, 160));
    }
  }
  await browser.close();
})();
