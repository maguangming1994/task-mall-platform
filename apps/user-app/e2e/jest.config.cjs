const path = require('path')

process.env.NODE_ENV = 'production'

module.exports = {
  rootDir: path.resolve(__dirname, '..'),
  testMatch: ['<rootDir>/e2e/**/*.spec.cjs'],
  moduleFileExtensions: ['js', 'cjs', 'json', 'node'],
  testEnvironment: '@dcloudio/uni-automator/dist/environment.js',
  testEnvironmentOptions: {
    platform: 'mp-weixin',
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
