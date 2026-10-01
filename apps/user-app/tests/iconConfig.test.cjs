const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { test } = require('node:test')
const ts = require('typescript')

const source = fs.readFileSync(path.join(__dirname, '../src/utils/iconConfig.ts'), 'utf8')
const output = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 },
}).outputText
const context = vm.createContext({ exports: {} })
vm.runInContext(output, context)
const {
  deriveLocalIconCode,
  resolveIconSrc,
  resolveIconLabel,
  normalizeIconConfigs,
} = context.exports

const toAbsolute = (value) => `https://rwsc.jxgxnxs.cn${value}`

const activeConfig = (code, extra = {}) => ({
  code,
  svg_path: `/static/icons/${code}.svg`,
  fallback_char: null,
  label: null,
  is_active: true,
  ...extra,
})

test('deriveLocalIconCode 仅识别包内静态图标并归一化为小写 code', () => {
  assert.equal(deriveLocalIconCode('/static/icons/service-tasks.svg'), 'service-tasks')
  assert.equal(deriveLocalIconCode('/static/icons/TabBar-Home.SVG?v=2#x'), 'tabbar-home')
  assert.equal(deriveLocalIconCode('https://cdn.example.com/a.png'), null)
  assert.equal(deriveLocalIconCode('/storage/assets/uploaded.svg'), null)
  assert.equal(deriveLocalIconCode(''), null)
  assert.equal(deriveLocalIconCode(null), null)
  assert.equal(deriveLocalIconCode(undefined), null)
  assert.equal(deriveLocalIconCode(42), null)
})

test('resolveIconSrc 默认透传，配置启用后替换', () => {
  assert.equal(
    resolveIconSrc('/static/icons/service-tasks.svg', {}, toAbsolute),
    '/static/icons/service-tasks.svg',
  )
  const map = { 'service-tasks': activeConfig('service-tasks') }
  assert.equal(
    resolveIconSrc('/static/icons/service-tasks.svg', map, toAbsolute),
    '/static/icons/service-tasks.svg',
  )
  const replaced = {
    'service-tasks': activeConfig('service-tasks', { svg_path: 'https://cdn.example.com/new.svg' }),
  }
  assert.equal(
    resolveIconSrc('/static/icons/service-tasks.svg', replaced, toAbsolute),
    'https://cdn.example.com/new.svg',
  )
})

test('resolveIconSrc 支持换包内其他 SVG、相对路径转绝对地址', () => {
  const map = {
    a: activeConfig('a', { svg_path: '/static/icons/custom-a.svg' }),
    b: activeConfig('b', { svg_path: '/storage/assets/b.svg' }),
    c: activeConfig('c', { svg_path: 'data:image/svg+xml;utf8,<svg/>' }),
  }
  assert.equal(resolveIconSrc('/static/icons/a.svg', map, toAbsolute), '/static/icons/custom-a.svg')
  assert.equal(resolveIconSrc('/static/icons/b.svg', map, toAbsolute), 'https://rwsc.jxgxnxs.cn/storage/assets/b.svg')
  assert.equal(resolveIconSrc('/static/icons/c.svg', map, toAbsolute), 'data:image/svg+xml;utf8,<svg/>')
})

test('resolveIconSrc 停用配置、空路径、装修远程图均保持原值', () => {
  const disabled = { 'service-tasks': activeConfig('service-tasks', { is_active: false, svg_path: '/x.svg' }) }
  assert.equal(
    resolveIconSrc('/static/icons/service-tasks.svg', disabled, toAbsolute),
    '/static/icons/service-tasks.svg',
  )
  const emptyPath = { 'service-tasks': activeConfig('service-tasks', { svg_path: '   ' }) }
  assert.equal(
    resolveIconSrc('/static/icons/service-tasks.svg', emptyPath, toAbsolute),
    '/static/icons/service-tasks.svg',
  )
  // 页面装修下发的远程图优先级最高，图标配置不得拦截。
  assert.equal(
    resolveIconSrc('https://cdn.example.com/decorated.png', { 'service-tasks': activeConfig('service-tasks') }, toAbsolute),
    'https://cdn.example.com/decorated.png',
  )
})

test('resolveIconLabel 仅在包内入口启用配置且有文字时覆盖', () => {
  const map = {
    'service-tasks': activeConfig('service-tasks', { label: '任务中心' }),
    'service-orders': activeConfig('service-orders', { label: '  ' }),
    'service-invite': activeConfig('service-invite', { is_active: false, label: '不应出现' }),
  }
  assert.equal(resolveIconLabel('我的任务', map, '/static/icons/service-tasks.svg'), '任务中心')
  assert.equal(resolveIconLabel('我的订单', map, '/static/icons/service-orders.svg'), '我的订单')
  assert.equal(resolveIconLabel('邀请好友', map, '/static/icons/service-invite.svg'), '邀请好友')
  assert.equal(resolveIconLabel('我的任务', map, 'https://cdn.example.com/a.png'), '我的任务')
  assert.equal(resolveIconLabel('默认', {}, '/static/icons/unknown.svg'), '默认')
})

test('normalizeIconConfigs 只保留启用且结构合法的条目', () => {
  const map = normalizeIconConfigs([
    activeConfig('Service-Tasks', { label: '我的任务', fallback_char: '任' }),
    activeConfig('service-orders', { is_active: false }),
    { code: '', svg_path: '/x.svg', is_active: true },
    { code: 'no-path', svg_path: '', is_active: true },
    null,
    { code: 'x' },
    activeConfig('module-wallet', { svg_path: '/static/icons/module-wallet.svg', label: '' }),
  ])
  assert.deepEqual(Object.keys(map).sort(), ['module-wallet', 'service-tasks'])
  assert.equal(map['service-tasks'].code, 'service-tasks')
  assert.equal(map['service-tasks'].label, '我的任务')
  assert.equal(map['module-wallet'].label, '')
  assert.equal(Object.keys(normalizeIconConfigs(null)).length, 0)
  assert.equal(Object.keys(normalizeIconConfigs('nope')).length, 0)
})
