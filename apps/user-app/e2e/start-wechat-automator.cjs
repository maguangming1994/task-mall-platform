const { spawnSync } = require('child_process')
const path = require('path')

const cliPath = process.env.WECHAT_DEVTOOLS_CLI
  || path.join(
    process.env['ProgramFiles(x86)'] || 'C:/Program Files (x86)',
    'Tencent',
    '\u5fae\u4fe1web\u5f00\u53d1\u8005\u5de5\u5177',
    'cli.bat',
  )
const args = process.argv.slice(2)
const projectIndex = args.indexOf('--project')
const portIndex = args.indexOf('--auto-port')
const projectPath = projectIndex >= 0 ? args[projectIndex + 1] : path.resolve(__dirname, '../dist/build/mp-weixin')
const port = portIndex >= 0 ? args[portIndex + 1] : String(process.env.WECHAT_AUTOMATOR_PORT || 9420)
const result = spawnSync('powershell.exe', [
  '-NoProfile',
  '-NonInteractive',
  '-Command',
  '& $env:WECHAT_CLI_PATH auto --project $env:WECHAT_PROJECT_PATH --auto-port $env:WECHAT_PORT',
], {
  encoding: 'utf8',
  env: {
    ...process.env,
    WECHAT_CLI_PATH: cliPath,
    WECHAT_PROJECT_PATH: projectPath,
    WECHAT_PORT: String(port),
  },
  windowsHide: true,
})
const output = `${result.stdout || ''}${result.stderr || ''}`

process.stdout.write(output)
if (
  result.error
  || result.status !== 0
  || /\[error\]|service port disabled|\u670d\u52a1\u7aef\u53e3\u5df2\u5173\u95ed/i.test(output)
) {
  console.error(
    '\n\u65e0\u6cd5\u8fde\u63a5\u5fae\u4fe1\u5f00\u53d1\u8005\u5de5\u5177\u3002\u8bf7\u5728\u5f00\u53d1\u8005\u5de5\u5177\u4e2d\u6253\u5f00：\u8bbe\u7f6e -> \u5b89\u5168\u8bbe\u7f6e -> \u670d\u52a1\u7aef\u53e3，\u7136\u540e\u91cd\u8bd5\u3002',
  )
  process.exit(1)
}
