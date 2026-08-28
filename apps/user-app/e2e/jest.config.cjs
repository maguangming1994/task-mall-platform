const path = require('path')

process.env.NODE_ENV = 'production'
// The mini program is built by the package script before Jest starts. The
// automator treats `compile: false` as a hint and may still spawn npm.cmd on
// Windows; Node.js 24 rejects that direct batch-file spawn with EINVAL.
process.env.UNI_AUTOMATOR_COMPILE = 'false'

module.exports = {
  rootDir: path.resolve(__dirname, '..'),
  testMatch: ['<rootDir>/e2e/**/*.spec.cjs'],
  moduleFileExtensions: ['js', 'cjs', 'json', 'node'],
  testEnvironment: '@dcloudio/uni-automator/dist/environment.js',
  globalTeardown: '@dcloudio/uni-automator/dist/teardown.js',
  testEnvironmentOptions: {
    platform: 'mp-weixin',
    port: Number(process.env.UNI_AUTOMATOR_RUNTIME_PORT || 9520),
    projectPath: path.resolve(__dirname, '..'),
    cliPath: path.resolve(__dirname, '..'),
    compile: false,
    timeout: 240000,
    'mp-weixin': {
      executablePath: process.env.WECHAT_DEVTOOLS_CLI
        || path.join(
          process.env['ProgramFiles(x86)'] || 'C:/Program Files (x86)',
          'Tencent',
          '\u5fae\u4fe1web\u5f00\u53d1\u8005\u5de5\u5177',
          'cli.bat',
        ),
      port: Number(process.env.WECHAT_AUTOMATOR_PORT || 9420),
      launch: false,
      teardown: 'disconnect',
    },
  },
  testTimeout: 90000,
  verbose: true,
}
