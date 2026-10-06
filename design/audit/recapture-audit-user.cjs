// 麻广明（13911112222）登录态补截：空态待数据的 21 个页面 + 数据已变化的 05/37/21
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
const H = { 'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'audit-seed-user-001', 'X-Device-Name': 'Playwright Audit' };
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

// 种子产出的关键 id（AuditScreenshotDataSeeder）
const ID = {
  mallOrder: '01M45BASM2NVYY7HY8BQD4ANXS',   // 商城订单（fulfilled 运输中）→ 13/55
  mallOrderDone: '01M45BCKT7SZNDDP9WHT7V7FFS', // 已完成商城订单（可申请售后）→ 40
  afterSale: '01M45BCKWM2GM33AD5EK884QJS',  // 商城售后单（审核中）→ 41
  shOrderPaid: '01M45B9QRZEW3DMQXEX5PACXBX', // 我买到的-待收货 → 29
  shOrderDone: '01M45B9QS3M1KEJ755S0M28QGN', // 我买到的-已完成 → 30 评价
  shOrderShip: '01M45B9QSBSF0TY4G59D4APRAW', // 我卖出的-待发货 → 31
  shRefund: '01M45B9QSJ1N0F4EBRPER63JV7',   // 退款处理中 → 32
  activity: '01M45BCKZ879S9YVT1XAAC11WF',   // 活动 → 43
  conv: '01M45BCKZY8ADAV6RBJE6SH33A',       // 商品卡会话 → 27
};

(async () => {
  await apiPost('/auth/phone/verification-codes', { phone: '13911112222', purpose: 'login' });
  const auth = await apiPost('/auth/phone/login', { phone: '13911112222', verification_code: '246810' });
  const token = auth.access_token;
  console.log('login ok:', auth.user?.nickname, 'phone_bound=', auth.user?.phone_bound);

  // 接受全部待确认协议，避免页面被拦截
  const pending = await apiGet('/me/legal-consents/pending', token);
  if (Array.isArray(pending)) {
    for (const d of pending) {
      const did = d.id ?? d.version_id ?? d.legal_document_version_id;
      if (!did) continue;
      await fetch(API + '/me/legal-consents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...H, Authorization: `Bearer ${token}`, 'Idempotency-Key': `audit-legal-${did}-mgm` },
        body: JSON.stringify({ legal_document_version_id: did, action: 'accept' }),
      });
    }
    console.log('legal accepted:', pending.length);
  }

  const authPayload = {
    accessToken: auth.access_token, refreshToken: auth.refresh_token,
    expiresAt: Date.now() + (auth.expires_in || 3600) * 1000, user: auth.user,
  };

  const jobs = [
    ['01-home', '/pages/index/index', true],
    ['05-account', '/pages/account/index'],
    ['13-exchange-success', `/pages/payment-result/index?orderId=${ID.mallOrder}&status=success`],
    ['14-orders', '/pages/orders/index'],
    ['19-coupons', '/pages/coupons/index'],
    ['21-sh-market', '/pages/second-hand/index'],
    ['25-sh-orders', '/pages/second-hand-orders/index'],
    ['26-chat-list', '/pages/chat/index'],
    ['27-chat', `/pages/chat-detail/index?id=${ID.conv}`],
    ['28-sh-my-list', '/pages/second-hand-my/index'],
    ['29-sh-order-detail', `/pages/second-hand-order-detail/index?id=${ID.shOrderPaid}`],
    ['30-sh-rate', `/pages/second-hand-rate/index?id=${ID.shOrderDone}`],
    ['31-sh-ship', `/pages/second-hand-ship/index?id=${ID.shOrderShip}`],
    ['32-sh-refund', `/pages/second-hand-refund/index?id=${ID.shRefund}`],
    ['37-address', '/pages/addresses/index'],
    ['39-as-list', '/pages/after-sales/index'],
    ['40-as-apply', `/pages/after-sale-apply/index?orderId=${ID.mallOrderDone}&type=refund`],
    ['41-as-detail', `/pages/after-sale-detail/index?id=${ID.afterSale}`],
    ['42-act-list', '/pages/community-activities/index'],
    ['43-act-detail', `/pages/community-activity-detail/index?id=${ID.activity}`],
    ['50-cart', '/pages/cart/index'],
    ['55-order-detail', `/pages/order-detail/index?id=${ID.mallOrder}`],
    ['56-coupon-center', '/pages/coupon-center/index'],
  ];

  const browser = await chromium.launch({ executablePath: resolveChromium() });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  await context.addInitScript((p) => {
    try { localStorage.setItem('task_mall_auth', JSON.stringify({ type: 'object', data: p })); } catch {}
  }, authPayload);
  const page = await context.newPage();

  for (const [name, route, dismissPopup] of jobs) {
    try {
      await page.goto(BASE + '/#' + route, { waitUntil: 'load', timeout: 30000 });
      await page.waitForTimeout(2400);
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
