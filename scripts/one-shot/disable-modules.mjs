/**
 * 一次性脚本：进入小程序审核期，把会触发审核拒的模块全部关闭。
 *
 * 与 enable-modules.mjs 互为镜像：
 *   - review_in_progress → enabled（打开审核总开关，前端隐藏钱包/充值/提现，文案切回「积分」）
 *   - 其余 8 个业务/实验开关 → disabled（社区/资料/关注/实名/外部通知 等，共 9 个目标）
 *   - module_withdraw 不用手动改，审核总开关 enabled 后会被动态关闭
 *
 * 全程走 admin API，保留审计日志和版本号校验。
 *
 * 运行：
 *   node scripts/one-shot/disable-modules.mjs
 */

import readline from 'node:readline';
import { stdin as input, stdout as output } from 'node:process';

const BASE_URL = process.env.TASK_MALL_BASE_URL ?? 'https://rwsc.jxgxnxs.cn';
const REASON = '进入小程序审核期，关闭可能触发审核拒绝的功能';

// 与 enable-modules.mjs 完全反向。
// module_withdraw 不在列表里，它会被 review_in_progress=enabled 动态关闭。
const TARGETS = [
  { code: 'review_in_progress', mode: 'enabled', rollout_percentage: 100 },
  { code: 'module_community', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_community_post', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_material', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_material_post', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_material_request', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_follow', mode: 'disabled', rollout_percentage: 0 },
  { code: 'module_identity_verification', mode: 'disabled', rollout_percentage: 0 },
  { code: 'external_notifications', mode: 'disabled', rollout_percentage: 0 },
];

// ---------- 工具函数 ----------

function ask(question, { hidden = false } = {}) {
  return new Promise((resolve) => {
    const rl = readline.createInterface({ input, output, terminal: true });
    if (hidden) {
      const originalWrite = rl._writeToOutput.bind(rl);
      rl._writeToOutput = (str) => {
        if (str.includes('\n') || str.includes('\r')) originalWrite(str);
      };
    }
    rl.question(question, (answer) => {
      rl.close();
      if (hidden) output.write('\n');
      resolve(answer.trim());
    });
  });
}

async function api(path, { method = 'GET', token, body } = {}) {
  const res = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({}));
  return { status: res.status, json };
}

function fail(msg, extra) {
  console.error(`\n❌ ${msg}`);
  if (extra) console.error(JSON.stringify(extra, null, 2));
  process.exit(1);
}

// ---------- 主流程 ----------

async function main() {
  console.log(`\n🎯 目标环境: ${BASE_URL}`);
  console.log(`📝 改动原因: ${REASON}`);
  console.log(`📋 待改开关 ${TARGETS.length} 个:\n`);
  TARGETS.forEach((t) => console.log(`   ${t.mode === 'enabled' ? '🟢' : '⚪'} ${t.code} → ${t.mode}`));

  console.log('\n请使用具有 deployment.manage 权限的超管账号登录。\n');

  const account = await ask('账号: ');
  if (!account) fail('账号不能为空');
  const password = await ask('密码(不回显): ', { hidden: true });
  if (!password) fail('密码不能为空');

  // 1) 登录
  console.log('\n[1/5] 登录...');
  let loginRes = await api('/api/v1/admin/auth/login', {
    method: 'POST',
    body: { account, password },
  });
  if (loginRes.status === 202 || loginRes.json?.data?.mfa_required) {
    const challenge = loginRes.json.data;
    const code = await ask('需要 MFA，请输入 6 位验证码: ', { hidden: true });
    loginRes = await api('/api/v1/admin/auth/mfa/verify', {
      method: 'POST',
      body: {
        challenge_id: challenge.challenge_id,
        challenge_token: challenge.challenge_token,
        code,
      },
    });
  }
  if (loginRes.status !== 200) fail('登录失败', loginRes.json);
  const token = loginRes.json.data?.access_token;
  if (!token) fail('响应里没有 access_token', loginRes.json);
  console.log('   ✅ 登录成功');

  // 2) 触发 recent_verification
  console.log('[2/5] 触发 recent_verification...');
  const verifyRes = await api('/api/v1/admin/auth/verify', {
    method: 'POST',
    token,
    body: { password },
  });
  if (verifyRes.status !== 200) {
    if (verifyRes.json?.code === 'ADMIN_AUTH_MFA_REQUIRED') {
      const mfaCode = await ask('再次输入 MFA 验证码: ', { hidden: true });
      const retry = await api('/api/v1/admin/auth/verify', {
        method: 'POST',
        token,
        body: { password, mfa_code: mfaCode },
      });
      if (retry.status !== 200) fail('recent_verification 失败', retry.json);
    } else {
      fail('recent_verification 失败', verifyRes.json);
    }
  }
  console.log('   ✅ 验证通过，15 分钟内可改 flag');

  // 3) 拉所有 flag 当前状态
  console.log('[3/5] 拉取当前 flag 状态...');
  const flagsRes = await api('/api/v1/admin/operations/release-control', { token });
  if (flagsRes.status !== 200) fail('拉取 flag 列表失败', flagsRes.json);
  const allFlags = flagsRes.json.data?.feature_flags ?? [];
  const flagByCode = Object.fromEntries(allFlags.map((f) => [f.code, f]));
  console.log(`   ✅ 拿到 ${allFlags.length} 个 flag`);

  // 4) 逐个改
  console.log('[4/5] 逐个更新...');
  const results = [];
  for (const target of TARGETS) {
    const existing = flagByCode[target.code];
    if (!existing) {
      console.log(`   ⚠️  ${target.code}: DB 里没这条记录，跳过`);
      results.push({ code: target.code, ok: false, reason: 'NOT_FOUND_IN_DB' });
      continue;
    }
    if (existing.mode === target.mode && existing.rollout_percentage === target.rollout_percentage) {
      console.log(`   ⏭️  ${target.code}: 已是 ${target.mode}，跳过`);
      results.push({ code: target.code, ok: true, reason: 'ALREADY_SET', version: existing.version });
      continue;
    }
    const put = await api(`/api/v1/admin/operations/feature-flags/${target.code}`, {
      method: 'PUT',
      token,
      body: {
        mode: target.mode,
        rollout_percentage: target.rollout_percentage,
        audience: existing.audience ?? {},
        expected_version: existing.version,
        reason: REASON,
      },
    });
    if (put.status !== 200) {
      console.error(`   ❌ ${target.code} 失败:`, put.json);
      results.push({ code: target.code, ok: false, reason: put.json?.code ?? 'HTTP_' + put.status });
    } else {
      const newVersion = put.json.data?.version;
      flagByCode[target.code] = put.json.data;
      console.log(`   ✅ ${target.code}: ${existing.mode} → ${target.mode} (v${existing.version} → v${newVersion})`);
      results.push({ code: target.code, ok: true, version: newVersion });
    }
  }

  // 5) 验证
  console.log('\n[5/5] 验证 wechat-mini 端 client-capabilities...');
  const verify = await fetch(`${BASE_URL}/api/v1/client-capabilities`, {
    headers: { 'X-Client-Platform': 'wechat-mini' },
  });
  const verifyJson = await verify.json();
  const data = verifyJson.data ?? {};
  console.log(`   review_status: ${data.review_status} (期望: reviewing)`);
  const watchList = TARGETS.map((t) => t.code).filter((c) => c.startsWith('module_') || c === 'external_notifications');
  watchList.push('module_withdraw'); // 提现应被动态关
  for (const code of watchList) {
    const v = data.feature_flags?.[code];
    const expected = false; // disable 脚本后期望全是 false
    const icon = v === expected ? '🟢' : '🔴';
    console.log(`   ${icon} ${code}: ${v} (期望: ${expected})`);
  }

  console.log('\n📊 改动汇总:');
  results.forEach((r) => {
    console.log(`   ${r.ok ? '✅' : '❌'} ${r.code} ${r.reason ?? ''}`);
  });

  const failedCount = results.filter((r) => !r.ok).length;
  if (failedCount > 0) {
    console.log(`\n⚠️  ${failedCount} 个失败，请检查`);
    process.exit(2);
  }
  console.log('\n🎉 全部完成，已进入审核模式');
  console.log('💡 提醒：');
  console.log('   - 用户端需要杀掉小程序进程重开才能看到变化');
  console.log('   - 提交审核后，等微信审核通过再跑 enable-modules.mjs 恢复');
}

main().catch((err) => fail('未捕获异常', { message: err.message, stack: err.stack }));
