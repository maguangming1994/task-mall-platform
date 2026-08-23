import { spawn } from 'node:child_process'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const outputRoot = join(root, 'design', 'ui', '整体UI总览')
const mobileOutput = join(outputRoot, '移动端')
const adminOutput = join(outputRoot, 'PC管理端')
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const debugPort = 9333
const captureScope = process.env.UI_CAPTURE_SCOPE || 'all'

const sleep = ms => new Promise(resolvePromise => setTimeout(resolvePromise, ms))

async function firstPublicItem(path) {
  try {
    const response = await fetch(`http://127.0.0.1:8000/api/v1/${path}`)
    if (!response.ok) return null
    const payload = await response.json()
    return payload?.data?.items?.[0] ?? null
  } catch {
    return null
  }
}

class CdpClient {
  constructor(url) {
    this.socket = new WebSocket(url)
    this.nextId = 1
    this.pending = new Map()
  }

  async connect() {
    await new Promise((resolvePromise, reject) => {
      this.socket.addEventListener('open', resolvePromise, { once: true })
      this.socket.addEventListener('error', reject, { once: true })
    })
    this.socket.addEventListener('message', event => {
      const message = JSON.parse(event.data)
      if (!message.id) return
      const pending = this.pending.get(message.id)
      if (!pending) return
      this.pending.delete(message.id)
      if (message.error) pending.reject(new Error(message.error.message))
      else pending.resolve(message.result)
    })
  }

  send(method, params = {}, sessionId) {
    const id = this.nextId++
    return new Promise((resolvePromise, reject) => {
      this.pending.set(id, { resolve: resolvePromise, reject })
      this.socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }))
    })
  }

  close() {
    this.socket.close()
  }
}

async function waitForChrome() {
  for (let attempt = 0; attempt < 60; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/version`)
      if (response.ok) return response.json()
    } catch {
      // Chrome 仍在启动。
    }
    await sleep(250)
  }
  throw new Error('无法连接用于截图的 Chrome。')
}

async function createPage(client, url, viewport) {
  const { targetId } = await client.send('Target.createTarget', { url: 'about:blank' })
  const { sessionId } = await client.send('Target.attachToTarget', { targetId, flatten: true })
  await client.send('Page.enable', {}, sessionId)
  await client.send('Runtime.enable', {}, sessionId)
  await client.send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: viewport.scale ?? 1,
    mobile: viewport.mobile ?? false,
    screenWidth: viewport.width,
    screenHeight: viewport.height,
  }, sessionId)
  await navigate(client, sessionId, url)
  return { targetId, sessionId }
}

async function evaluate(client, sessionId, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  }, sessionId)
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || '页面脚本执行失败。')
  }
  return result.result.value
}

async function navigate(client, sessionId, url, waitMs = 1600) {
  await client.send('Page.navigate', { url }, sessionId)
  for (let attempt = 0; attempt < 80; attempt += 1) {
    const ready = await evaluate(client, sessionId, 'document.readyState')
    if (ready === 'complete') break
    await sleep(100)
  }
  await sleep(waitMs)
}

async function screenshot(client, sessionId, path, viewport, options = {}) {
  const { fullPage = true, scrollY = 0 } = options
  await evaluate(client, sessionId, `
    (() => {
      const dismiss = [...document.querySelectorAll('button, view, text, span')]
        .find(node => node.textContent.trim() === '我知道了');
      if (dismiss) dismiss.click();
      document.querySelectorAll('.uni-toast, .ant-message, .ant-notification, .popup-mask').forEach(node => node.remove());
      window.scrollTo(0, ${Math.max(0, scrollY)});
      return true;
    })()
  `)
  await sleep(250)
  const captureOptions = {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: true,
  }
  if (fullPage) {
    const { contentSize } = await client.send('Page.getLayoutMetrics', {}, sessionId)
    const width = Math.max(viewport.width, Math.ceil(contentSize.width))
    const height = Math.min(16000, Math.max(viewport.height, Math.ceil(contentSize.height)))
    captureOptions.clip = { x: 0, y: 0, width, height, scale: 1 }
  }
  const { data } = await client.send('Page.captureScreenshot', captureOptions, sessionId)
  await writeFile(path, Buffer.from(data, 'base64'))
}

async function establishUserSession(client, sessionId) {
  const result = await evaluate(client, sessionId, `
    (async () => {
      const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Client-Platform': 'h5-browser',
        'X-Device-Id': 'ui-overview-device-2026'
      };
      const codeResponse = await fetch('/api/v1/auth/phone/verification-codes', {
        method: 'POST',
        headers,
        body: JSON.stringify({ phone: '13800000001', purpose: 'login' })
      });
      if (!codeResponse.ok) return { ok: false, payload: await codeResponse.json() };
      const response = await fetch('/api/v1/auth/phone/login', {
        method: 'POST',
        headers,
        body: JSON.stringify({ phone: '13800000001', verification_code: '246810' })
      });
      const payload = await response.json();
      if (!response.ok) return { ok: false, payload };
      const tokens = payload.data;
      const auth = {
        accessToken: tokens.access_token,
        refreshToken: tokens.refresh_token,
        expiresAt: Date.now() + tokens.expires_in * 1000,
        user: tokens.user
      };
      localStorage.setItem('task_mall_auth', JSON.stringify({ type: 'object', data: auth }));
      localStorage.setItem('task_mall_device_id', 'ui-overview-device-2026');
      return { ok: true, token: tokens.access_token };
    })()
  `)
  if (!result?.ok) throw new Error(`用户端演示账号登录失败：${JSON.stringify(result?.payload)}`)
  return result.token
}

async function clickText(client, sessionId, text, selector = 'button, view, text, span') {
  const clicked = await evaluate(client, sessionId, `
    (() => {
      const target = ${JSON.stringify(text)};
      const node = [...document.querySelectorAll(${JSON.stringify(selector)})]
        .find(item => item.textContent.trim() === target || item.textContent.includes(target));
      if (!node) return false;
      node.click();
      return true;
    })()
  `)
  if (clicked) await sleep(1100)
  return clicked
}

async function clickSelector(client, sessionId, selector) {
  const clicked = await evaluate(client, sessionId, `
    (() => {
      const node = document.querySelector(${JSON.stringify(selector)});
      if (!node) return false;
      node.click();
      return true;
    })()
  `)
  if (clicked) await sleep(650)
  return clicked
}

async function fillInputs(client, sessionId, values, selector = 'input, textarea') {
  return evaluate(client, sessionId, `
    (() => {
      const values = ${JSON.stringify(values)};
      const nodes = [...document.querySelectorAll(${JSON.stringify(selector)})];
      values.forEach((value, index) => {
        const node = nodes[index];
        if (!node) return;
        const descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value');
        descriptor?.set?.call(node, value);
        node.dispatchEvent(new Event('input', { bubbles: true }));
        node.dispatchEvent(new Event('change', { bubbles: true }));
      });
      return nodes.length;
    })()
  `)
}

async function loadUserReviewContext(client, sessionId, token) {
  return evaluate(client, sessionId, `
    (async () => {
      const token = ${JSON.stringify(token)};
      const baseHeaders = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + token,
        'X-Client-Platform': 'h5-browser',
        'X-Device-Id': 'ui-overview-device-2026'
      };
      const request = async (path, options = {}) => {
        try {
          const response = await fetch('/api/v1' + path, {
            ...options,
            headers: { ...baseHeaders, ...(options.headers || {}) }
          });
          const payload = await response.json().catch(() => null);
          return { ok: response.ok, data: payload?.data ?? null };
        } catch {
          return { ok: false, data: null };
        }
      };

      const [ordersResult, refundsResult, exchangesResult, supportResult, productsResult] = await Promise.all([
        request('/orders'), request('/refunds'), request('/exchanges'), request('/support/tickets'),
        request('/products')
      ]);
      const order = ordersResult.data?.items?.find(item => item.items?.length) || null;
      const refund = refundsResult.data?.items?.[0] || null;
      const exchange = exchangesResult.data?.items?.[0] || null;

      let tickets = supportResult.data?.items || [];
      if (!tickets.length) {
        const created = await request('/support/tickets', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'ui-overview-support-ticket' },
          body: JSON.stringify({
            category: 'consultation',
            subject: 'UI 评审演示工单',
            description: '用于展示客服咨询、投诉和处理进度界面，不包含真实个人信息。',
            contact_preference: 'in_app',
            evidence_file_ids: []
          })
        });
        if (created.ok && created.data) tickets = [created.data];
      }

      let cart = (await request('/cart')).data;
      let selected = cart?.items?.find(item => item.selected && item.valid) || cart?.items?.find(item => item.valid) || null;
      if (!selected) {
        const products = productsResult.data?.items || [];
        const candidates = products.flatMap(product => (product.skus || []).map(sku => ({ product, sku })))
          .filter(({ sku }) => sku.stock > 0 && sku.purchase_options?.some(option => option.enabled))
          .sort((left, right) => Number(left.sku.fulfillment_type === 'logistics') - Number(right.sku.fulfillment_type === 'logistics'));
        const candidate = candidates[0];
        const option = candidate?.sku.purchase_options.find(item => item.enabled && item.purchase_mode === 'cash')
          || candidate?.sku.purchase_options.find(item => item.enabled);
        if (candidate && option) {
          const added = await request('/cart/items', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sku_id: candidate.sku.id, purchase_mode: option.purchase_mode, quantity: 1 })
          });
          if (added.ok) cart = added.data;
          selected = cart?.items?.find(item => item.selected && item.valid) || cart?.items?.find(item => item.valid) || null;
        }
      }
      if (selected && !selected.selected) {
        const updated = await request('/cart/items/' + selected.id, {
          method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ selected: true })
        });
        if (updated.ok) cart = updated.data;
      }

      let addresses = (await request('/addresses')).data || [];
      const hasLogistics = !!cart?.items?.some(item => item.selected && item.valid && item.fulfillment_type === 'logistics');
      if (hasLogistics && !addresses.length) {
        const createdAddress = await request('/addresses', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'ui-overview-address' },
          body: JSON.stringify({ recipient_name: '演示用户', phone: '13800000001', province: '湖北省', city: '武汉市', district: '洪山区', detail: '评审演示地址 1 号', is_default: true })
        });
        if (createdAddress.ok && createdAddress.data) addresses = [createdAddress.data];
      }
      const checkoutReady = !!cart?.items?.some(item => item.selected && item.valid) && (!hasLogistics || addresses.length > 0);

      return {
        checkoutReady,
        orderId: order?.id || null,
        orderItemId: order?.items?.[0]?.id || null,
        afterSaleId: refund?.id || exchange?.id || null,
        afterSaleType: refund ? 'refund' : exchange ? 'exchange' : null,
        supportTicketId: tickets[0]?.id || null
      };
    })()
  `)
}

async function captureMobileReviewScenes(client, sessionId, context, viewport) {
  const baseUrl = 'http://127.0.0.1:5173/?ui-overview=1#'
  await navigate(client, sessionId, `${baseUrl}/pages/support/index`)
  await screenshot(client, sessionId, join(mobileOutput, '17-客服与投诉.png'), viewport)
  if (await clickText(client, sessionId, '新建工单', '.new-button')) {
    await fillInputs(client, sessionId, ['订单退款进度需要协助', '请协助核对订单状态、预计处理时间和后续操作。'])
    await screenshot(client, sessionId, join(mobileOutput, '18-新建客服工单.png'), viewport, { fullPage: false })
  }
  if (context.supportTicketId) {
    await navigate(client, sessionId, `${baseUrl}/pages/support/index`)
    if (await clickSelector(client, sessionId, '.ticket')) {
      await screenshot(client, sessionId, join(mobileOutput, '19-客服工单详情.png'), viewport, { fullPage: false })
    }
  }

  await navigate(client, sessionId, `${baseUrl}/pages/privacy/index`)
  await screenshot(client, sessionId, join(mobileOutput, '20-隐私权利管理.png'), viewport)

  if (context.checkoutReady) {
    await navigate(client, sessionId, `${baseUrl}/pages/checkout/index`)
    if (await clickText(client, sessionId, '校验价格并查看优惠券', '.submit')) {
      await screenshot(client, sessionId, join(mobileOutput, '21-结算与优惠券.png'), viewport)
    }
  }
  if (context.orderId && context.orderItemId) {
    await navigate(client, sessionId, `${baseUrl}/pages/after-sale-apply/index?orderId=${encodeURIComponent(context.orderId)}&itemId=${encodeURIComponent(context.orderItemId)}`)
    await screenshot(client, sessionId, join(mobileOutput, '22-售后申请.png'), viewport)
  }
  if (context.afterSaleId && context.afterSaleType) {
    await navigate(client, sessionId, `${baseUrl}/pages/after-sale-detail/index?id=${encodeURIComponent(context.afterSaleId)}&type=${encodeURIComponent(context.afterSaleType)}`)
    await screenshot(client, sessionId, join(mobileOutput, '23-售后进度详情.png'), viewport)
  }
}

async function captureAdminReviewScenes(client, sessionId, viewport) {
  await clickMenu(client, sessionId, '订单与售后')
  await screenshot(client, sessionId, join(adminOutput, '17-订单履约列表.png'), viewport)
  if (await clickText(client, sessionId, '详情', 'button')) {
    await screenshot(client, sessionId, join(adminOutput, '18-订单履约详情.png'), viewport, { fullPage: false })
    await clickSelector(client, sessionId, '.ant-drawer-close')
  }
  if (await clickText(client, sessionId, '发货', 'button')) {
    await screenshot(client, sessionId, join(adminOutput, '19-创建履约记录.png'), viewport, { fullPage: false })
    await clickSelector(client, sessionId, '.ant-modal-close')
  }

  await clickMenu(client, sessionId, '商城中心')
  if (await clickText(client, sessionId, '新建运费模板', 'button')) {
    await screenshot(client, sessionId, join(adminOutput, '20-运费模板编辑.png'), viewport, { fullPage: false })
    await clickSelector(client, sessionId, '.ant-modal-close')
  }
  if (await clickText(client, sessionId, '新建优惠券', 'button')) {
    await screenshot(client, sessionId, join(adminOutput, '21-优惠券模板编辑.png'), viewport, { fullPage: false })
    await clickSelector(client, sessionId, '.ant-modal-close')
  }
}

async function establishAdminSession(client, sessionId) {
  const result = await evaluate(client, sessionId, `
    (async () => {
      const response = await fetch('/api/v1/admin/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ account: 'preview_admin', password: 'TaskMall2026!Preview' })
      });
      const payload = await response.json();
      if (!response.ok) return { ok: false, payload };
      const tokens = payload.data;
      if (tokens.mfa_required) return { ok: false, payload: { message: '演示账号要求 MFA' } };
      sessionStorage.setItem('task_mall_admin_access', tokens.access_token);
      sessionStorage.setItem('task_mall_admin_refresh', tokens.refresh_token);
      return { ok: true };
    })()
  `)
  if (!result?.ok) throw new Error(`管理端演示账号登录失败：${JSON.stringify(result?.payload)}`)
}

async function clickMenu(client, sessionId, label) {
  const clicked = await evaluate(client, sessionId, `
    (() => {
      const label = ${JSON.stringify(label)};
      const node = [...document.querySelectorAll('.ant-menu-item')]
        .find(item => item.textContent.trim() === label);
      if (!node) return false;
      node.click();
      return true;
    })()
  `)
  if (!clicked) throw new Error(`未找到管理端菜单：${label}`)
  await sleep(1500)
}

async function run() {
  await mkdir(mobileOutput, { recursive: true })
  await mkdir(adminOutput, { recursive: true })
  const profileDir = await mkdtemp(join(tmpdir(), 'task-mall-ui-'))
  const chrome = spawn(chromePath, [
    '--headless=new',
    `--remote-debugging-port=${debugPort}`,
    `--user-data-dir=${profileDir}`,
    '--disable-gpu',
    '--disable-extensions',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
  ], { stdio: 'ignore' })

  try {
    const version = await waitForChrome()
    const client = new CdpClient(version.webSocketDebuggerUrl)
    await client.connect()

    const mobileViewport = { width: 390, height: 844, scale: 1, mobile: true }
    const mobile = await createPage(client, 'http://127.0.0.1:5173/#/', mobileViewport)
    const userToken = await establishUserSession(client, mobile.sessionId)
    await navigate(client, mobile.sessionId, 'http://127.0.0.1:5173/?ui-overview=1#/')
    const userReviewContext = await loadUserReviewContext(client, mobile.sessionId, userToken)
    const [taskItem, productItem, activityItem] = await Promise.all([
      firstPublicItem('tasks'),
      firstPublicItem('products'),
      firstPublicItem('community/activities'),
    ])
    const mobilePages = [
      ['01-首页.png', 'http://127.0.0.1:5173/?ui-overview=1#/'],
      ['02-任务大厅.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/tasks/index'],
      ['03-精选商城.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/products/index'],
      ['04-校园社区.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/community/index'],
      ['05-我的账户.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/account/index'],
      ['06-我的订单.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/orders/index'],
      ['07-消息中心.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/notifications/index'],
      ['08-资产中心.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/assets/index'],
      ['09-邀请好友.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/referrals/index'],
      ['10-购物车.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/cart/index'],
      ['11-售后服务.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/after-sales/index'],
      ['12-地址管理.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/addresses/index'],
      ['13-发布动态.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/community-compose/index'],
      ['14-用户协议.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/legal/index?key=user_agreement'],
      ...(taskItem?.id ? [['15-任务详情.png', `http://127.0.0.1:5173/?ui-overview=1#/pages/task-detail/index?id=${taskItem.id}`]] : []),
      ...(productItem?.id ? [['16-商品详情.png', `http://127.0.0.1:5173/?ui-overview=1#/pages/product-detail/index?id=${productItem.id}`]] : []),
      ['24-校园组团大厅.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/community-activities/index'],
      ['25-发起校园组团.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/community-activity-compose/index'],
      ...(activityItem?.id ? [['26-校园组团详情.png', `http://127.0.0.1:5173/?ui-overview=1#/pages/community-activity-detail/index?id=${activityItem.id}`]] : []),
      ['27-社区互动消息.png', 'http://127.0.0.1:5173/?ui-overview=1#/pages/notifications/index?category=community'],
    ]
    const pagesToCapture = captureScope === 'community-message'
      ? mobilePages.filter(([fileName]) => fileName === '27-社区互动消息.png')
      : mobilePages
    for (const [fileName, url] of pagesToCapture) {
      await navigate(client, mobile.sessionId, url)
      if (fileName === '27-社区互动消息.png') {
        await evaluate(client, mobile.sessionId, `
          (() => {
            const item = [...document.querySelectorAll('.category-chip')]
              .find(node => node.textContent.includes('社区'));
            item?.click();
            return Boolean(item);
          })()
        `)
        await sleep(1000)
      }
      await screenshot(client, mobile.sessionId, join(mobileOutput, fileName), mobileViewport)
    }
    if (captureScope === 'community-message') {
      await client.send('Target.closeTarget', { targetId: mobile.targetId })
      client.close()
      return
    }
    await captureMobileReviewScenes(client, mobile.sessionId, userReviewContext, mobileViewport)

    const adminViewport = { width: 1440, height: 1000, scale: 1, mobile: false }
    const admin = await createPage(client, 'http://127.0.0.1:5174/', adminViewport)
    await screenshot(client, admin.sessionId, join(adminOutput, '01-登录页.png'), adminViewport)
    await establishAdminSession(client, admin.sessionId)
    await navigate(client, admin.sessionId, 'http://127.0.0.1:5174/')
    const adminModules = [
      ['02-运营工作台.png', '工作台'],
      ['03-用户中心.png', '用户中心'],
      ['04-协议与隐私.png', '协议与隐私'],
      ['05-任务中心.png', '任务中心'],
      ['06-商城中心.png', '商城中心'],
      ['07-订单与售后.png', '订单与售后'],
      ['08-资产与财务.png', '资产与财务'],
      ['09-社区内容.png', '社区内容'],
      ['10-运营与消息.png', '运营与消息'],
      ['11-配置中心.png', '配置中心'],
      ['12-上线准备.png', '上线准备'],
      ['13-权限与审计.png', '权限与审计'],
    ]
    for (const [fileName, label] of adminModules) {
      await clickMenu(client, admin.sessionId, label)
      await screenshot(client, admin.sessionId, join(adminOutput, fileName), adminViewport)
    }
    await clickMenu(client, admin.sessionId, '社区内容')
    if (await clickText(client, admin.sessionId, '组团活动审核', '.ant-tabs-tab')) {
      await screenshot(client, admin.sessionId, join(adminOutput, '22-校园组团审核.png'), adminViewport)
    }
    if (await clickText(client, admin.sessionId, '校园社区装修', '.ant-tabs-tab')) {
      await screenshot(client, admin.sessionId, join(adminOutput, '23-校园社区装修.png'), adminViewport)
    }
    await captureAdminReviewScenes(client, admin.sessionId, adminViewport)

    await client.send('Target.closeTarget', { targetId: mobile.targetId })
    await client.send('Target.closeTarget', { targetId: admin.targetId })
    client.close()
  } finally {
    chrome.kill()
    await sleep(300)
    await rm(profileDir, { recursive: true, force: true })
  }
}

run().then(() => {
  process.stdout.write(`整体 UI 截图已生成：${outputRoot}\n`)
}).catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`)
  process.exitCode = 1
})
