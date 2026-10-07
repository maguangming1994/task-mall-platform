#!/usr/bin/env node
/**
 * 小程序发布脚本：以后台配置的 AppID 为唯一数据源，自动补丁构建产物并上传到微信平台。
 *
 * 用法：
 *   npm run build:mp-weixin && node scripts/publish-wechat.cjs -v 1.0.0 -d "版本说明"
 *
 * 流程：
 *   1. 从后端 GET {API_BASE}/app-config 读取小程序 AppID
 *      （后台「配置中心 → 第三方服务 → 微信应用」保存的值为准，无需人工传递）
 *   2. 将 AppID 写入 dist/build/mp-weixin/project.config.json
 *   3. 调用本机微信开发者工具 CLI 上传（要求开发者工具已登录且已开启服务端口）
 *
 * 可选环境变量：
 *   WECHAT_API_BASE       后端 API 地址，默认 https://rwsc.jxgxnxs.cn/api/v1
 *   WECHAT_DEVTOOLS_CLI   开发者工具 cli 路径，默认 Windows 常见安装路径
 *   MINI_APPID            跳过接口、直接指定 AppID（本地调试用）
 */
'use strict'

const fs = require('node:fs')
const path = require('node:path')
const { spawnSync } = require('node:child_process')

// 读取命令行参数：-v 版本号，-d 版本描述
const args = process.argv.slice(2)
function argOf(name, fallback) {
  const index = args.indexOf(name)
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback
}

const version = argOf('-v', '1.0.0')
const desc = argOf('-d', `任务商城小程序 ${version}`)
const apiBase = process.env.WECHAT_API_BASE || 'https://rwsc.jxgxnxs.cn/api/v1'
const cliPath = process.env.WECHAT_DEVTOOLS_CLI
  || 'C:\\Program Files (x86)\\Tencent\\微信web开发者工具\\cli.bat'
const distDir = path.resolve(__dirname, '..', 'dist', 'build', 'mp-weixin')
const projectConfigPath = path.join(distDir, 'project.config.json')

// 生产域名本机直连可能被网络策略拦截，遵循系统代理环境变量（Node 24 支持）
if ((process.env.HTTPS_PROXY || process.env.HTTP_PROXY) && process.env.NODE_USE_ENV_PROXY === undefined) {
  process.env.NODE_USE_ENV_PROXY = '1'
}

function fail(message) {
  console.error(`[publish-wechat] 失败：${message}`)
  process.exit(1)
}

async function resolveAppid() {
  if (process.env.MINI_APPID) {
    console.log(`[publish-wechat] 使用环境变量 MINI_APPID：${process.env.MINI_APPID}`)
    return process.env.MINI_APPID
  }
  console.log(`[publish-wechat] 从后端读取 AppID：GET ${apiBase}/app-config`)
  const response = await fetch(`${apiBase}/app-config`)
  if (!response.ok) fail(`接口返回 HTTP ${response.status}，请确认后端已部署 /app-config 端点`)
  const payload = await response.json()
  const appid = payload && payload.data && payload.data.mini_program_appid
  if (!appid) fail('接口未返回 mini_program_appid，请先在后台「配置中心 → 第三方服务 → 微信应用」保存小程序 AppID')
  console.log(`[publish-wechat] 后台配置的 AppID：${appid}`)
  return appid
}

function patchProjectConfig(appid) {
  if (!fs.existsSync(projectConfigPath)) {
    fail(`未找到 ${projectConfigPath}，请先执行 npm run build:mp-weixin`)
  }
  const config = JSON.parse(fs.readFileSync(projectConfigPath, 'utf8'))
  config.appid = appid
  fs.writeFileSync(projectConfigPath, `${JSON.stringify(config, null, 2)}\n`, 'utf8')
  console.log(`[publish-wechat] project.config.json 的 appid 已更新为 ${appid}`)
}

function upload() {
  if (!fs.existsSync(cliPath)) {
    fail(`未找到微信开发者工具 CLI：${cliPath}，请安装开发者工具或用 WECHAT_DEVTOOLS_CLI 指定路径`)
  }
  console.log(`[publish-wechat] 调用开发者工具上传：版本 ${version}（${desc}）`)
  const result = spawnSync(cliPath, [
    'upload', '--project', distDir, '-v', version, '-d', desc,
  ], { stdio: 'inherit' })
  if (result.error) fail(`CLI 调用异常：${result.error.message}`)
  if (result.status !== 0) fail(`CLI 上传退出码 ${result.status}，请确认开发者工具已登录、服务端口已开启，且登录账号是该小程序的开发者`)
  console.log('[publish-wechat] 上传成功。请到 mp.weixin.qq.com 「版本管理 → 开发版本」提交审核。')
}

resolveAppid()
  .then((appid) => {
    patchProjectConfig(appid)
    upload()
  })
  .catch((error) => fail(error && error.message ? error.message : String(error)))
