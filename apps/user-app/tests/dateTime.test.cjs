const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { test } = require('node:test')
const ts = require('typescript')

const source = fs.readFileSync(path.join(__dirname, '../src/utils/dateTime.ts'), 'utf8')
const output = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 },
}).outputText
const context = vm.createContext({ exports: {} })
// Mini-program engines may ignore locale arguments or omit Intl entirely.
vm.runInContext(`Intl = undefined; Date.prototype.toLocaleString = Date.prototype.toLocaleDateString = function () { throw Error('Device locale must not be used') }`, context)
vm.runInContext(output, context)
const { formatDate, formatDateTime } = context.exports

test('UTC API values display Beijing time using a 24-hour clock', () => {
  assert.equal(formatDateTime('2026-09-28T06:30:12.123456Z'), '2026-09-28 14:30')
  assert.equal(formatDateTime('2026-09-28T16:00:00Z'), '2026-09-29 00:00')
  assert.equal(formatDateTime('2026-12-31T23:59:59Z'), '2027-01-01 07:59')
  assert.equal(formatDate('2026-09-28T18:30:00Z'), '2026-09-29')
})

test('explicit offsets represent the same instant without double conversion', () => {
  for (const value of ['2026-09-28T14:30:00+08:00', '2026-09-28T14:30:00+0800', '2026-09-28T02:30:00-04:00', '2026-09-28T12:00:00+05:30']) {
    assert.equal(formatDateTime(value), '2026-09-28 14:30')
  }
})

test('legacy server datetimes and calendar dates are Beijing-local', () => {
  assert.equal(formatDateTime('2026-09-28 14:30:00'), '2026-09-28 14:30')
  assert.equal(formatDateTime('2026-09-28T14:30'), '2026-09-28 14:30')
  assert.equal(formatDate('2026-09-28'), '2026-09-28')
  assert.equal(formatDateTime(' 2026-09-28 '), '2026-09-28 00:00')
  assert.equal(formatDate('2024-02-29'), '2024-02-29')
})

test('missing and invalid values never leak English or raw timestamps', () => {
  for (const value of [undefined, null, '', ' ', 'Invalid Date', '2026-02-29', '2026-09-31', '2026-13-01', '2026-00-01', '2026-09-28T24:00:00Z', '2026-09-28T12:60:00Z', '2026-09-28T12:00:60Z', '2026-09-28T12:00:00+14:30']) {
    assert.equal(formatDateTime(value), '—')
    assert.equal(formatDate(value), '—')
  }
  assert.equal(formatDateTime(null, '未设置'), '未设置')
  assert.equal(formatDate(undefined, '待生效'), '待生效')
})

test('results do not depend on the device timezone', () => {
  const original = process.env.TZ
  try {
    for (const zone of ['UTC', 'America/Los_Angeles', 'Asia/Shanghai', 'Asia/Tokyo']) {
      process.env.TZ = zone
      assert.equal(formatDateTime('2026-09-28T06:30:00Z'), '2026-09-28 14:30')
      assert.equal(formatDateTime('2026-09-28 14:30:00'), '2026-09-28 14:30')
      assert.equal(formatDate('2026-09-28'), '2026-09-28')
    }
  } finally {
    if (original === undefined) delete process.env.TZ
    else process.env.TZ = original
  }
})

test('pages cannot introduce device-locale date rendering again', () => {
  const root = path.join(__dirname, '../src/pages')
  for (const directory of fs.readdirSync(root, { withFileTypes: true })) {
    if (!directory.isDirectory()) continue
    const file = path.join(root, directory.name, 'index.vue')
    if (!fs.existsSync(file)) continue
    const script = fs.readFileSync(file, 'utf8').split('<script setup lang="ts">')[1]?.split('</script>')[0] || ''
    assert.doesNotMatch(script, /toLocale(?:Date|Time)String\s*\(/, file)
    assert.doesNotMatch(script, /(?:new Date\([^\n]*|\bdate)\.toLocaleString\s*\(/, file)
  }
})
