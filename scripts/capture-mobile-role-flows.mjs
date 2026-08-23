import { spawn } from 'node:child_process'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const configuredOutput = process.env.ROLE_FLOW_OUTPUT
const outputRoot = configuredOutput
  ? (configuredOutput.includes(':') ? configuredOutput : resolve(root, configuredOutput))
  : join(root, '角色流程截图', '移动端V2')
const visitorOutput = join(outputRoot, '01-游客')
const userOutput = join(outputRoot, '02-普通注册用户')
const publisherOutput = join(outputRoot, '03-平台发布管理员')
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const debugPort = 9334
const baseUrl = 'http://127.0.0.1:5173'
const viewport = { width: 390, height: 844, scale: 1, mobile: true }
const scope = process.env.ROLE_FLOW_SCOPE || 'all'

const sleep = ms => new Promise(resolvePromise => setTimeout(resolvePromise, ms))

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

  close() { this.socket.close() }
}

async function waitForChrome() {
  for (let attempt = 0; attempt < 80; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/version`)
      if (response.ok) return response.json()
    } catch { /* Chrome is still starting. */ }
    await sleep(250)
  }
  throw new Error('无法连接用于角色流程截图的 Chrome。')
}

async function evaluate(client, sessionId, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  }, sessionId)
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || '页面脚本执行失败。')
  return result.result.value
}

async function navigate(client, sessionId, url, waitMs = 2600) {
  await client.send('Page.navigate', { url }, sessionId)
  for (let attempt = 0; attempt < 80; attempt += 1) {
    if (await evaluate(client, sessionId, 'document.readyState') === 'complete') break
    await sleep(100)
  }
  await sleep(waitMs)
}

async function waitFor(client, sessionId, expression, timeoutMs = 12000) {
  const startedAt = Date.now()
  while (Date.now() - startedAt < timeoutMs) {
    if (await evaluate(client, sessionId, expression)) return true
    await sleep(200)
  }
  return false
}

async function createPage(client, url) {
  const { targetId } = await client.send('Target.createTarget', { url: 'about:blank' })
  const { sessionId } = await client.send('Target.attachToTarget', { targetId, flatten: true })
  await client.send('Page.enable', {}, sessionId)
  await client.send('Runtime.enable', {}, sessionId)
  await client.send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: viewport.scale,
    mobile: viewport.mobile,
    screenWidth: viewport.width,
    screenHeight: viewport.height,
  }, sessionId)
  await navigate(client, sessionId, url)
  return { targetId, sessionId }
}

async function capture(client, sessionId, path, options = {}) {
  const { scrollY = 0, keepPopup = false, fullPage = false } = options
  await evaluate(client, sessionId, `
    (() => {
      document.querySelectorAll('.uni-toast, .ant-message, .ant-notification').forEach(node => node.remove());
      ${keepPopup ? '' : "document.querySelectorAll('.popup-mask').forEach(node => node.remove());"}
      window.scrollTo(0, ${Math.max(0, scrollY)});
      return true;
    })()
  `)
  await sleep(280)
  const screenshotOptions = { format: 'png', fromSurface: true, captureBeyondViewport: true }
  if (fullPage) {
    const { contentSize } = await client.send('Page.getLayoutMetrics', {}, sessionId)
    screenshotOptions.clip = { x: 0, y: 0, width: Math.max(viewport.width, Math.ceil(contentSize.width)), height: Math.min(16000, Math.max(viewport.height, Math.ceil(contentSize.height))), scale: 1 }
  }
  const { data } = await client.send('Page.captureScreenshot', screenshotOptions, sessionId)
  await writeFile(path, Buffer.from(data, 'base64'))
}

async function shot(client, page, folder, fileName, route, options = {}) {
  await navigate(client, page.sessionId, `${baseUrl}/?role-flow=${Date.now()}#${route}`)
  if (options.before) await options.before()
  await capture(client, page.sessionId, join(folder, fileName), options)
  process.stdout.write(`已生成 ${folder.split('\\').at(-1)}/${fileName}\n`)
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
  await sleep(700)
  return clicked
}

async function fillInputs(client, sessionId, values, selector = 'input') {
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

async function fillAll(client, sessionId, value, selector) {
  return evaluate(client, sessionId, `
    (() => {
      const nodes = [...document.querySelectorAll(${JSON.stringify(selector)})];
      nodes.forEach(node => {
        const descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value');
        descriptor?.set?.call(node, ${JSON.stringify(value)});
        node.dispatchEvent(new Event('input', { bubbles: true }));
        node.dispatchEvent(new Event('change', { bubbles: true }));
      });
      return nodes.length;
    })()
  `)
}

async function establishUserSession(client, sessionId) {
  const result = await evaluate(client, sessionId, `
    (async () => {
      const headers = {
        'Content-Type': 'application/json', Accept: 'application/json',
        'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'mobile-role-flow-2026'
      };
      await fetch('/api/v1/auth/phone/verification-codes', {
        method: 'POST', headers, body: JSON.stringify({ phone: '13800000001', purpose: 'login' })
      });
      const response = await fetch('/api/v1/auth/phone/login', {
        method: 'POST', headers,
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
      localStorage.setItem('task_mall_device_id', 'mobile-role-flow-2026');
      return { ok: true, token: tokens.access_token };
    })()
  `)
  if (!result?.ok) throw new Error(`普通用户演示账号登录失败：${JSON.stringify(result?.payload)}`)
  return result.token
}

async function loadTaskContext(client, sessionId, token) {
  const context = await evaluate(client, sessionId, `
    (async () => {
      const headers = { Accept: 'application/json', 'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'mobile-role-flow-2026' };
      const hallPayload = await (await fetch('/api/v1/tasks', { headers })).json();
      const items = hallPayload.data?.items || [];
      const task = items.find(item => item.can_claim) || items[0];
      if (!task) return { ok: false, message: '没有公开任务可用于截图' };
      const authHeaders = { ...headers, Authorization: 'Bearer ${token}' };
      const claimPayload = await (await fetch('/api/v1/task-claims', { headers: authHeaders })).json();
      const claims = claimPayload.data?.items || [];
      let claim = claims.find(item => ['submit', 'resubmit_failed_steps'].includes(item.workflow?.primary_action?.code)
        && (item.steps || []).every(step => !['image', 'text_image'].includes(step.input_type))) || claims[0];
      if (!claim && task.can_claim) {
        const response = await fetch('/api/v1/tasks/' + task.id + '/claims', {
          method: 'POST', headers: { ...authHeaders, 'Content-Type': 'application/json', 'Idempotency-Key': 'role-flow-' + Date.now() }, body: '{}'
        });
        const payload = await response.json();
        if (response.ok) claim = payload.data;
      }
      return { ok: true, taskId: task.id, claimId: claim?.id || null };
    })()
  `)
  if (!context?.ok) throw new Error(context?.message || '任务流程上下文加载失败')
  return context
}

async function loadUserReviewContext(client, sessionId, token) {
  return evaluate(client, sessionId, `
    (async () => {
      const token = ${JSON.stringify(token)};
      const baseHeaders = {
        Accept: 'application/json', Authorization: 'Bearer ' + token,
        'X-Client-Platform': 'h5-browser', 'X-Device-Id': 'mobile-role-flow-2026'
      };
      const request = async (path, options = {}) => {
        try {
          const response = await fetch('/api/v1' + path, { ...options, headers: { ...baseHeaders, ...(options.headers || {}) } });
          const payload = await response.json().catch(() => null);
          return { ok: response.ok, data: payload?.data ?? null };
        } catch { return { ok: false, data: null }; }
      };

      const [ordersResult, refundsResult, exchangesResult, supportResult, productsResult] = await Promise.all([
        request('/orders'), request('/refunds'), request('/exchanges'), request('/support/tickets'), request('/products')
      ]);
      const order = ordersResult.data?.items?.find(item => item.items?.length) || null;
      const refund = refundsResult.data?.items?.[0] || null;
      const exchange = exchangesResult.data?.items?.[0] || null;

      let tickets = supportResult.data?.items || [];
      if (!tickets.length) {
        const created = await request('/support/tickets', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'mobile-role-flow-support-ticket' },
          body: JSON.stringify({
            category: 'consultation', subject: '角色流程评审演示工单',
            description: '用于展示客服咨询、投诉和处理进度界面，不包含真实个人信息。',
            contact_preference: 'in_app', evidence_file_ids: []
          })
        });
        if (created.ok && created.data) tickets = [created.data];
      }

      let cart = (await request('/cart')).data;
      let selected = cart?.items?.find(item => item.selected && item.valid) || cart?.items?.find(item => item.valid) || null;
      if (!selected) {
        const candidates = (productsResult.data?.items || [])
          .flatMap(product => (product.skus || []).map(sku => ({ product, sku })))
          .filter(({ sku }) => sku.stock > 0 && sku.purchase_options?.some(option => option.enabled))
          .sort((left, right) => Number(left.sku.fulfillment_type === 'logistics') - Number(right.sku.fulfillment_type === 'logistics'));
        const candidate = candidates[0];
        const option = candidate?.sku.purchase_options.find(item => item.enabled && item.purchase_mode === 'cash')
          || candidate?.sku.purchase_options.find(item => item.enabled);
        if (candidate && option) {
          const added = await request('/cart/items', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
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
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'mobile-role-flow-address' },
          body: JSON.stringify({ recipient_name: '演示用户', phone: '13800000001', province: '湖北省', city: '武汉市', district: '洪山区', detail: '角色流程评审地址 1 号', is_default: true })
        });
        if (createdAddress.ok && createdAddress.data) addresses = [createdAddress.data];
      }

      return {
        checkoutReady: !!cart?.items?.some(item => item.selected && item.valid) && (!hasLogistics || addresses.length > 0),
        orderId: order?.id || null,
        orderItemId: order?.items?.[0]?.id || null,
        afterSaleId: refund?.id || exchange?.id || null,
        afterSaleType: refund ? 'refund' : exchange ? 'exchange' : null,
        supportTicketId: tickets[0]?.id || null
      };
    })()
  `)
}

async function captureUserCommerceReview(client, page, context) {
  const s = page.sessionId
  if (context.checkoutReady) {
    await shot(client, page, userOutput, '15A-结算拆单与优惠券.png', '/pages/checkout/index', {
      fullPage: true,
      before: async () => { await clickText(client, s, '校验价格并查看优惠券', '.submit'); await sleep(900) },
    })
  }
  if (context.orderId && context.orderItemId) {
    await shot(client, page, userOutput, '18A-售后申请表单.png', `/pages/after-sale-apply/index?orderId=${encodeURIComponent(context.orderId)}&itemId=${encodeURIComponent(context.orderItemId)}`, { fullPage: true })
  }
  if (context.afterSaleId && context.afterSaleType) {
    await shot(client, page, userOutput, '18B-售后进度详情.png', `/pages/after-sale-detail/index?id=${encodeURIComponent(context.afterSaleId)}&type=${encodeURIComponent(context.afterSaleType)}`, { fullPage: true })
  }
}

async function captureUserServiceReview(client, page, context) {
  await shot(client, page, userOutput, '25A-隐私权利管理.png', '/pages/privacy/index', { fullPage: true })
  await shot(client, page, userOutput, '25B-客服二维码.png', '/pages/support/index', { fullPage: true })
}

async function captureVisitor(client, page, taskId) {
  const s = page.sessionId
  await shot(client, page, visitorOutput, '01-游客首页.png', '/', { keepPopup: false })
  await shot(client, page, visitorOutput, '02-首页公告弹窗.png', '/', { keepPopup: true })
  await shot(client, page, visitorOutput, '03-公开任务大厅.png', '/pages/tasks/index')
  await shot(client, page, visitorOutput, '04-任务分类筛选.png', '/pages/tasks/index', {
    before: () => clickText(client, s, '新手', '.chip'),
  })
  await shot(client, page, visitorOutput, '05-任务详情.png', `/pages/task-detail/index?id=${taskId}`)
  await shot(client, page, visitorOutput, '06-领取登录拦截.png', `/pages/task-detail/index?id=${taskId}`, {
    before: () => clickText(client, s, '立即领取', '.claim-button'),
  })
  await shot(client, page, visitorOutput, '07-精选商城.png', '/pages/products/index')
  await shot(client, page, visitorOutput, '08-成长社区.png', '/pages/community/index')
  await shot(client, page, visitorOutput, '09-手机号登录.png', '/pages/login/index')
  await shot(client, page, visitorOutput, '10-登录信息已填写.png', '/pages/login/index', {
    before: async () => { await fillInputs(client, s, ['13800000001', '246810']); await clickText(client, s, '我已阅读并同意', '.agreement-row') },
  })
  await shot(client, page, visitorOutput, '11-用户协议.png', '/pages/legal-consent/index?mode=read&type=user_agreement')
  await shot(client, page, visitorOutput, '12-隐私政策.png', '/pages/legal-consent/index?mode=read&type=privacy_policy')
}

async function captureUserLogin(client, page) {
  const s = page.sessionId
  await navigate(client, s, `${baseUrl}/?login-flow=${Date.now()}#/pages/login/index`)
  await fillInputs(client, s, ['13800000001', ''])
  await clickText(client, s, '我已阅读并同意', '.agreement-row')
  await clickText(client, s, '获取验证码', 'button')
  await fillInputs(client, s, ['13800000001', '246810'])
  await clickText(client, s, '登录 / 注册', 'button')
  const loggedIn = await waitFor(client, s, `location.hash === '#/' && document.body.innerText.includes('本周成长进度')`)
  if (!loggedIn) {
    const snapshot = await evaluate(client, s, `({ href: location.href, text: document.body.innerText.slice(0, 700) })`)
    throw new Error(`手机号登录后未返回已登录首页：${JSON.stringify(snapshot)}`)
  }
  await capture(client, s, join(userOutput, '00-手机号登录成功.png'))
  process.stdout.write('已生成 02-普通注册用户/00-手机号登录成功.png\n')
}

async function captureUser(client, page, context) {
  const s = page.sessionId
  if (scope === 'user-orders') {
    await shot(client, page, userOutput, '16-我的订单.png', '/pages/orders/index')
    await shot(client, page, userOutput, '17-订单状态筛选.png', '/pages/orders/index', {
      before: () => clickText(client, s, '待确认', '.tab'),
    })
    await shot(client, page, userOutput, '18-售后规则与申请.png', '/pages/after-sales/index')
    await captureUserCommerceReview(client, page, context)
    return
  }
  if (scope === 'user-service') {
    await captureUserServiceReview(client, page, context)
    return
  }
  if (scope === 'user-assets') {
    await shot(client, page, userOutput, '11-资产总览.png', '/pages/assets/index')
    await shot(client, page, userOutput, '12-提现与手续费.png', '/pages/assets/index', { scrollY: 620 })
    return
  }
  if (scope === 'user-claim') {
    if (!context.claimId) throw new Error('没有可用于任务执行截图的领取记录。')
    await shot(client, page, userOutput, '08-任务执行总览.png', `/pages/task-claim/index?id=${context.claimId}`)
    await shot(client, page, userOutput, '09-任务步骤与材料.png', `/pages/task-claim/index?id=${context.claimId}`, { scrollY: 250 })
    await shot(client, page, userOutput, '10-任务文字结果已填写.png', `/pages/task-claim/index?id=${context.claimId}`, {
      scrollY: 300,
      before: () => fillAll(client, s, '已按任务要求完成操作，关键结果与页面信息核对无误。', 'textarea'),
    })
    const editable = await evaluate(client, s, `!!document.querySelector('.submit-button')`)
    if (editable) {
      await clickText(client, s, '提交审核', '.submit-button')
      await clickText(client, s, '确定', '.uni-modal__btn, button, view')
      const submitted = await waitFor(client, s, `document.body.innerText.includes('审核中') && !document.querySelector('.submit-button')`)
      if (!submitted) throw new Error('任务提交后未进入审核中状态')
      await capture(client, s, join(userOutput, '10B-任务已提交审核.png'), { scrollY: 0 })
      process.stdout.write('已生成 02-普通注册用户/10B-任务已提交审核.png\n')
    }
    return
  }
  await shot(client, page, userOutput, '01-登录后首页.png', '/')
  await shot(client, page, userOutput, '02-任务大厅.png', '/pages/tasks/index')
  await shot(client, page, userOutput, '03-任务详情与奖励.png', `/pages/task-detail/index?id=${context.taskId}`)
  await shot(client, page, userOutput, '04-任务详情规则.png', `/pages/task-detail/index?id=${context.taskId}`, { scrollY: 720 })
  await shot(client, page, userOutput, '05-领取任务确认.png', `/pages/task-detail/index?id=${context.taskId}`, {
    before: () => clickText(client, s, '立即领取', '.claim-button'),
  })
  await shot(client, page, userOutput, '06-我的任务.png', '/pages/tasks/index', {
    before: () => clickText(client, s, '我的任务', '.tab'),
  })
  await shot(client, page, userOutput, '07-我的任务状态筛选.png', '/pages/tasks/index', {
    before: async () => { await clickText(client, s, '我的任务', '.tab'); await clickText(client, s, '审核中', '.mine-status-tab') },
  })
  if (context.claimId) {
    await shot(client, page, userOutput, '08-任务执行总览.png', `/pages/task-claim/index?id=${context.claimId}`)
    await shot(client, page, userOutput, '09-任务步骤与材料.png', `/pages/task-claim/index?id=${context.claimId}`, { scrollY: 250 })
    await shot(client, page, userOutput, '10-任务文字结果已填写.png', `/pages/task-claim/index?id=${context.claimId}`, {
      scrollY: 300,
      before: () => fillInputs(client, s, ['已按任务要求完成操作，关键结果与页面信息核对无误。'], 'textarea'),
    })
  } else {
    await shot(client, page, userOutput, '08-任务执行空状态.png', '/pages/tasks/index', { before: () => clickText(client, s, '我的任务', '.tab') })
    await shot(client, page, userOutput, '09-任务材料指引.png', `/pages/task-detail/index?id=${context.taskId}`, { scrollY: 420 })
    await shot(client, page, userOutput, '10-任务审核规则.png', `/pages/task-detail/index?id=${context.taskId}`, { scrollY: 900 })
  }
  await shot(client, page, userOutput, '11-资产总览.png', '/pages/assets/index')
  await shot(client, page, userOutput, '12-提现与手续费.png', '/pages/assets/index', { scrollY: 620 })
  await shot(client, page, userOutput, '13-商城商品列表.png', '/pages/products/index')
  await shot(client, page, userOutput, '14-商品加入购物车.png', '/pages/products/index', {
    before: () => clickText(client, s, '加入购物车', 'button'),
  })
  await shot(client, page, userOutput, '15-购物车与结算规则.png', '/pages/cart/index')
  await captureUserCommerceReview(client, page, context)
  await shot(client, page, userOutput, '16-我的订单.png', '/pages/orders/index')
  await shot(client, page, userOutput, '17-订单状态筛选.png', '/pages/orders/index', {
    before: () => clickText(client, s, '待确认', '.tab'),
  })
  await shot(client, page, userOutput, '18-售后规则与申请.png', '/pages/after-sales/index')
  await shot(client, page, userOutput, '19-成长社区.png', '/pages/community/index')
  await shot(client, page, userOutput, '20-发布帖子编辑.png', '/pages/community/index', {
    before: () => clickText(client, s, '发布帖子', '.primary'),
  })
  await shot(client, page, userOutput, '21-社区评论互动.png', '/pages/community/index', {
    before: () => clickText(client, s, '查看评论', 'button'),
  })
  await shot(client, page, userOutput, '22-消息中心.png', '/pages/notifications/index')
  await shot(client, page, userOutput, '23-邀请好友奖励.png', '/pages/referrals/index')
  await shot(client, page, userOutput, '24-我的账户.png', '/pages/account/index')
  await shot(client, page, userOutput, '25-收货地址与隐私入口.png', '/pages/account/index', { scrollY: 760 })
  await captureUserServiceReview(client, page, context)
  await shot(client, page, userOutput, '26-余额充值.png', '/pages/wallet-recharge/index', { fullPage: true })
  await shot(client, page, userOutput, '27-每日签到.png', '/pages/check-in/index', { fullPage: true })
  await shot(client, page, userOutput, '28-邀请好友与海报入口.png', '/pages/referrals/index', { fullPage: true })
  await shot(client, page, userOutput, '29-校园组团大厅.png', '/pages/community-activities/index', { fullPage: true })
  await shot(client, page, userOutput, '30-发起校园组团.png', '/pages/community-activity-compose/index', { fullPage: true })
  await shot(client, page, userOutput, '31-协议与隐私中心.png', '/pages/legal/index', { fullPage: true })
}

async function capturePublisher(client, page) {
  const s = page.sessionId
  await shot(client, page, publisherOutput, '01-发布身份独立登录.png', '/pages/publisher/index')
  await shot(client, page, publisherOutput, '02-管理员凭证已填写.png', '/pages/publisher/index', {
    before: () => fillInputs(client, s, ['preview_admin', 'TaskMall2026!Preview', '']),
  })
  await navigate(client, s, `${baseUrl}/?publisher-flow=1#/pages/publisher/index`)
  await fillInputs(client, s, ['preview_admin', 'TaskMall2026!Preview', ''])
  await clickText(client, s, '独立验证并进入', '.primary-button')
  const publisherReady = await waitFor(client, s, `!!document.querySelector('.identity-strip')`)
  if (!publisherReady) {
    const message = await evaluate(client, s, `document.body.innerText.slice(0, 500)`)
    throw new Error(`发布身份验证未完成：${message}`)
  }
  await capture(client, s, join(publisherOutput, '03-发布身份已验证.png'))
  process.stdout.write('已生成 03-平台发布管理员/03-发布身份已验证.png\n')

  await fillInputs(client, s, [
    '移动端角色流程 V2 体验任务', '角色流程 V2', '', '完成指定操作并按步骤提交截图与文字结果，禁止提交无关内容。', '体验,截图,调研'
  ], 'input, textarea')
  await capture(client, s, join(publisherOutput, '04-基础信息已填写.png'))
  await clickText(client, s, '下一步', '.next-button')
  await capture(client, s, join(publisherOutput, '05-时限频率设置.png'))
  await clickText(client, s, '下一步', '.next-button')
  await capture(client, s, join(publisherOutput, '06-奖励预算预留.png'))
  await evaluate(client, s, `(() => { const inputs=[...document.querySelectorAll('.form-card input')]; const unit=inputs[0]; const quota=inputs[1]; if(unit){unit.value='999999';unit.dispatchEvent(new Event('input',{bubbles:true}))} if(quota){quota.value='9999';quota.dispatchEvent(new Event('input',{bubbles:true}))} return true })()`)
  await sleep(300)
  await capture(client, s, join(publisherOutput, '07-预算不足风险提示.png'))
  await fillInputs(client, s, ['100', '10'], '.form-card input')
  await clickText(client, s, '重新校验', '.refresh-button')
  await clickText(client, s, '下一步', '.next-button')
  await capture(client, s, join(publisherOutput, '08-任务步骤编辑.png'))
  await clickText(client, s, '添加步骤', '.add-step')
  await sleep(300)
  const stepEditors = await evaluate(client, s, `
    (() => {
      const editors=[...document.querySelectorAll('.step-editor')];
      const last=editors.at(-1); if(!last) return 0;
      const input=last.querySelector('input'); const textarea=last.querySelector('textarea');
      if(input){input.value='上传完成截图';input.dispatchEvent(new Event('input',{bubbles:true}))}
      if(textarea){textarea.value='上传能够清晰验证完成结果的页面截图，最多 3 张。';textarea.dispatchEvent(new Event('input',{bubbles:true}))}
      return editors.length;
    })()
  `)
  if (!stepEditors) throw new Error('未找到发布任务步骤编辑器')
  await capture(client, s, join(publisherOutput, '09-自定义验收步骤.png'), { scrollY: 300 })
  await clickText(client, s, '下一步', '.next-button')
  await capture(client, s, join(publisherOutput, '10-发布前确认.png'))
  await clickText(client, s, '已阅读并同意', '.agreement-row')
  await capture(client, s, join(publisherOutput, '11-发布规则已确认.png'))
  await clickText(client, s, '仅保存草稿', '.draft-button')
  const draftSaved = await waitFor(client, s, `document.body.innerText.includes('移动端角色流程 V2 体验任务') && document.body.innerText.includes('草稿 · 查看进度与管理')`)
  if (!draftSaved) throw new Error('发布任务草稿未出现在“我的发布”列表')
  await capture(client, s, join(publisherOutput, '12-发布管理总览.png'), { scrollY: 900, fullPage: true })
  const taskOpened = await evaluate(client, s, `(() => { const rows=[...document.querySelectorAll('.published-row')]; const node=rows.find(item=>item.textContent.includes('移动端角色流程 V2 体验任务')); if(!node)return false; node.click(); return true })()`)
  if (!taskOpened) throw new Error('未找到刚创建的发布任务')
  const detailReady = await waitFor(client, s, `document.body.innerText.includes('任务发布详情') || document.body.innerText.includes('发布任务详情')`)
  if (!detailReady) throw new Error('发布任务管理详情未打开')
  await capture(client, s, join(publisherOutput, '13-发布任务详情与操作.png'), { scrollY: 0, fullPage: true })
}

async function run() {
  await Promise.all([visitorOutput, userOutput, publisherOutput].map(folder => mkdir(folder, { recursive: true })))
  const profileDir = await mkdtemp(join(tmpdir(), 'task-mall-role-flow-'))
  const chrome = spawn(chromePath, [
    '--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profileDir}`,
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
  ], { stdio: 'ignore' })

  try {
    const version = await waitForChrome()
    const client = new CdpClient(version.webSocketDebuggerUrl)
    await client.connect()
    const page = await createPage(client, `${baseUrl}/#/`)

    const publicContext = await evaluate(client, page.sessionId, `
      (async () => { const payload=await (await fetch('/api/v1/tasks',{headers:{Accept:'application/json','X-Client-Platform':'h5-browser','X-Device-Id':'mobile-role-flow-2026'}})).json(); const items=payload.data?.items||[]; return { taskId: (items.find(item=>item.can_claim)||items[0])?.id || null } })()
    `)
    if (!publicContext.taskId) throw new Error('没有可用于角色流程截图的公开任务。')
    if (scope === 'all' || scope === 'visitor') await captureVisitor(client, page, publicContext.taskId)
    if (scope === 'user-login') await captureUserLogin(client, page)

    if (scope === 'all' || scope === 'user' || scope === 'user-claim' || scope === 'user-assets' || scope === 'user-orders' || scope === 'user-service') {
      const token = await establishUserSession(client, page.sessionId)
      await navigate(client, page.sessionId, `${baseUrl}/?user-session=1#/`)
      const [taskContext, reviewContext] = await Promise.all([
        loadTaskContext(client, page.sessionId, token),
        loadUserReviewContext(client, page.sessionId, token),
      ])
      await captureUser(client, page, { ...taskContext, ...reviewContext })
    }
    if (scope === 'all' || scope === 'publisher') await capturePublisher(client, page)

    await client.send('Target.closeTarget', { targetId: page.targetId })
    client.close()
  } finally {
    chrome.kill()
    await sleep(400)
    await rm(profileDir, { recursive: true, force: true })
  }
}

run().then(() => {
  process.stdout.write(`移动端角色流程 V2 已生成：${outputRoot}\n`)
}).catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`)
  process.exitCode = 1
})
