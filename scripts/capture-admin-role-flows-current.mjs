import { spawn } from 'node:child_process'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const deliveryRoot = process.env.ROLE_UI_DELIVERY_ROOT
  ? (process.env.ROLE_UI_DELIVERY_ROOT.includes(':') ? process.env.ROLE_UI_DELIVERY_ROOT : resolve(root, process.env.ROLE_UI_DELIVERY_ROOT))
  : join(root, '角色流程截图', 'UI设计师交付-20260823')
const outputRoot = join(deliveryRoot, 'PC管理端')
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const baseUrl = 'http://127.0.0.1:5174/'
const debugPort = Number(process.env.ADMIN_CAPTURE_DEBUG_PORT || 9336)
const viewport = { width: 1440, height: 1000, scale: 1 }
const password = 'RolePreview2026!'

const roles = [
  ['01-超级管理员', 'role_super_admin'],
  ['02-平台管理员', 'role_platform_admin'],
  ['03-运营管理员', 'role_operations_admin'],
  ['04-任务审核员', 'role_task_reviewer'],
  ['05-社区审核员', 'role_community_reviewer'],
  ['06-客服人员', 'role_customer_service'],
  ['07-仓储人员', 'role_warehouse'],
  ['08-财务人员', 'role_finance'],
  ['09-审计人员', 'role_auditor'],
]

const sleep = ms => new Promise(resolvePromise => setTimeout(resolvePromise, ms))
const safeName = value => value.replace(/[\\/:*?"<>|]/g, '-').trim()

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
  for (let attempt = 0; attempt < 100; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/version`)
      if (response.ok) return response.json()
    } catch { /* Chrome is still starting. */ }
    await sleep(200)
  }
  throw new Error('无法连接用于管理端角色截图的 Chrome。')
}

async function evaluate(client, sessionId, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression, awaitPromise: true, returnByValue: true, userGesture: true,
  }, sessionId)
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || '页面脚本执行失败。')
  return result.result.value
}

async function navigate(client, sessionId, url, waitMs = 1800) {
  await client.send('Page.navigate', { url }, sessionId)
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (await evaluate(client, sessionId, 'document.readyState') === 'complete') break
    await sleep(100)
  }
  await sleep(waitMs)
}

async function createPage(client) {
  const { targetId } = await client.send('Target.createTarget', { url: 'about:blank' })
  const { sessionId } = await client.send('Target.attachToTarget', { targetId, flatten: true })
  await client.send('Page.enable', {}, sessionId)
  await client.send('Runtime.enable', {}, sessionId)
  await client.send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width, height: viewport.height, deviceScaleFactor: viewport.scale,
    mobile: false, screenWidth: viewport.width, screenHeight: viewport.height,
  }, sessionId)
  await navigate(client, sessionId, baseUrl)
  return { targetId, sessionId }
}

async function screenshot(client, sessionId, path, { fullPage = true } = {}) {
  await evaluate(client, sessionId, `(() => {
    document.querySelectorAll('.ant-message, .ant-notification').forEach(node => node.remove());
    window.scrollTo(0, 0); return true;
  })()`)
  await sleep(260)
  const options = { format: 'png', fromSurface: true, captureBeyondViewport: true }
  if (fullPage) {
    const { contentSize } = await client.send('Page.getLayoutMetrics', {}, sessionId)
    options.clip = {
      x: 0, y: 0,
      width: Math.max(viewport.width, Math.ceil(contentSize.width)),
      height: Math.min(16000, Math.max(viewport.height, Math.ceil(contentSize.height))),
      scale: 1,
    }
  }
  const { data } = await client.send('Page.captureScreenshot', options, sessionId)
  await writeFile(path, Buffer.from(data, 'base64'))
}

async function fillLogin(client, sessionId, account) {
  await evaluate(client, sessionId, `(() => {
    const values = ${JSON.stringify([account, password])};
    [...document.querySelectorAll('input')].slice(0, 2).forEach((node, index) => {
      const descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value');
      descriptor?.set?.call(node, values[index]);
      node.dispatchEvent(new Event('input', { bubbles: true }));
      node.dispatchEvent(new Event('change', { bubbles: true }));
    });
    return true;
  })()`)
  await sleep(250)
}

async function establishSession(client, sessionId, account) {
  const result = await evaluate(client, sessionId, `(async () => {
    sessionStorage.clear();
    const response = await fetch('/api/v1/admin/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ account: ${JSON.stringify(account)}, password: ${JSON.stringify(password)} })
    });
    const payload = await response.json();
    if (!response.ok) return { ok: false, payload };
    const tokens = payload.data;
    if (tokens.mfa_required) return { ok: false, payload: { message: '演示账号要求 MFA' } };
    sessionStorage.setItem('task_mall_admin_access', tokens.access_token);
    sessionStorage.setItem('task_mall_admin_refresh', tokens.refresh_token);
    return { ok: true };
  })()`)
  if (!result?.ok) throw new Error(`${account} 登录失败：${JSON.stringify(result?.payload)}`)
}

async function waitForShell(client, sessionId) {
  for (let attempt = 0; attempt < 80; attempt += 1) {
    if (await evaluate(client, sessionId, `!!document.querySelector('.admin-layout .ant-menu-item')`)) return
    await sleep(200)
  }
  throw new Error('管理端角色工作台未就绪。')
}

async function menuLabels(client, sessionId) {
  return evaluate(client, sessionId, `[...document.querySelectorAll('.admin-sider .ant-menu-item')]
    .map(node => node.textContent.trim()).filter(Boolean)`)
}

async function clickMenu(client, sessionId, label) {
  const clicked = await evaluate(client, sessionId, `(() => {
    const target = ${JSON.stringify(label)};
    const node = [...document.querySelectorAll('.admin-sider .ant-menu-item')]
      .find(item => item.textContent.trim() === target);
    if (!node) return false; node.click(); return true;
  })()`)
  if (!clicked) throw new Error(`未找到菜单：${label}`)
  await sleep(1900)
}

async function clickText(client, sessionId, text) {
  return evaluate(client, sessionId, `(() => {
    const target = ${JSON.stringify(text)};
    const node = [...document.querySelectorAll('button,li,span,div')]
      .find(item => item.textContent.trim() === target);
    if (!node) return false; node.click(); return true;
  })()`)
}

async function captureRole(client, roleFolder, account) {
  const folder = join(outputRoot, roleFolder)
  await mkdir(folder, { recursive: true })
  const page = await createPage(client)
  try {
    await fillLogin(client, page.sessionId, account)
    await screenshot(client, page.sessionId, join(folder, '01-角色账号首次登录.png'), { fullPage: false })
    await establishSession(client, page.sessionId, account)
    await navigate(client, page.sessionId, `${baseUrl}?role-capture=${encodeURIComponent(account)}`)
    await waitForShell(client, page.sessionId)

    const labels = await menuLabels(client, page.sessionId)
    let index = 2
    for (const label of labels) {
      await clickMenu(client, page.sessionId, label)
      const fileName = `${String(index).padStart(2, '0')}-${safeName(label)}.png`
      await screenshot(client, page.sessionId, join(folder, fileName))
      process.stdout.write(`已生成 ${roleFolder}/${fileName}\n`)
      index += 1
    }

    const opened = await evaluate(client, page.sessionId, `(() => {
      const node=document.querySelector('.profile-button'); if(!node)return false; node.click(); return true;
    })()`)
    if (opened) {
      await sleep(450)
      await screenshot(client, page.sessionId, join(folder, `${String(index).padStart(2, '0')}-账号菜单.png`), { fullPage: false })
      if (await clickText(client, page.sessionId, '修改密码')) {
        await sleep(450)
        await screenshot(client, page.sessionId, join(folder, `${String(index + 1).padStart(2, '0')}-修改密码.png`), { fullPage: false })
      }
    }
  } finally {
    await client.send('Target.closeTarget', { targetId: page.targetId })
  }
}

async function run() {
  await mkdir(outputRoot, { recursive: true })
  const profileDir = await mkdtemp(join(tmpdir(), 'task-mall-admin-role-current-'))
  const chrome = spawn(chromePath, [
    '--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profileDir}`,
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
  ], { stdio: 'ignore' })
  try {
    const version = await waitForChrome()
    const client = new CdpClient(version.webSocketDebuggerUrl)
    await client.connect()
    for (const [folder, account] of roles) await captureRole(client, folder, account)
    client.close()
  } finally {
    chrome.kill()
    await sleep(400)
    await rm(profileDir, { recursive: true, force: true })
  }
}

run().then(() => {
  process.stdout.write(`管理端角色实页截图已生成：${outputRoot}\n`)
}).catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`)
  process.exitCode = 1
})
