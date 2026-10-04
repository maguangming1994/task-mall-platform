#!/usr/bin/env bash
# 在生产服务器上架 GitHub self-hosted runner，让镜像构建+推送 TCR 走国内网络。
# 用法（宝塔终端或 SSH，root 执行）：
#   RUNNER_REG_TOKEN=<注册token> bash scripts/server/setup-actions-runner.sh
# 注册 token 由维护者通过 GitHub API 现取（1 小时有效）：
#   POST /repos/maguangming1994/task-mall-platform/actions/runners/registration-token
set -Eeuo pipefail

REPO="maguangming1994/task-mall-platform"
RUNNER_USER="gh-runner"
RUNNER_DIR="/home/$RUNNER_USER/actions-runner"
RUNNER_NAME="task-mall-prod-1"
RUNNER_LABELS="self-hosted,linux,x64,task-mall-prod"
: "${RUNNER_REG_TOKEN:?需要 RUNNER_REG_TOKEN 环境变量（GitHub 注册 token，1 小时有效）}"

for c in docker git curl tar; do command -v "$c" >/dev/null || { echo "缺少命令：$c"; exit 1; }; done
docker buildx version >/dev/null 2>&1 || { echo "缺少 docker buildx 插件"; exit 1; }

echo "[1/5] 创建运行用户并加入 docker 组"
id "$RUNNER_USER" &>/dev/null || useradd -m -s /bin/bash "$RUNNER_USER"
usermod -aG docker "$RUNNER_USER"

echo "[2/5] 安装 Node.js 22（workflow 里 restore-campus-emblems.mjs 需要系统 node）"
if ! sudo -u "$RUNNER_USER" bash -lc 'command -v node && [ "$(node -v)" \> "v20" ]' 2>/dev/null; then
  if [ ! -x /usr/local/node22/bin/node ]; then
    curl -fsSL --connect-timeout 10 --max-time 300 \
      -o /tmp/node22.tar.xz "https://registry.npmmirror.com/-/binary/node/v22.20.0/node-v22.20.0-linux-x64.tar.xz"
    mkdir -p /usr/local/node22
    tar -xJf /tmp/node22.tar.xz -C /usr/local/node22 --strip-components=1
    rm -f /tmp/node22.tar.xz
  fi
  ln -sf /usr/local/node22/bin/node /usr/local/bin/node
  ln -sf /usr/local/node22/bin/npm /usr/local/bin/npm
fi
node -v

echo "[3/5] 配置 Docker 镜像加速与 live-restore（不自动重启 docker，由你确认时机）"
DAEMON_JSON=/etc/docker/daemon.json
if ! grep -q 'registry-mirrors' "$DAEMON_JSON" 2>/dev/null; then
  cp -a "$DAEMON_JSON" "$DAEMON_JSON.bak.$(date +%s)" 2>/dev/null || true
  python3 - <<'EOF' 2>/dev/null || node - <<'EOF2'
import json,os
p='/etc/docker/daemon.json'
d=json.load(open(p)) if os.path.exists(p) else {}
d.setdefault('registry-mirrors',['https://docker.m.daocloud.io','https://dockerproxy.net'])
d.setdefault('live-restore',True)
json.dump(d,open(p,'w'),indent=2,ensure_ascii=False)
print('daemon.json 已写入 registry-mirrors + live-restore')
EOF
const fs=require('fs');const p='/etc/docker/daemon.json';
const d=fs.existsSync(p)?JSON.parse(fs.readFileSync(p,'utf8')):{};
d['registry-mirrors']=d['registry-mirrors']||['https://docker.m.daocloud.io','https://dockerproxy.net'];
d['live-restore']=d['live-restore']!==undefined?d['live-restore']:true;
fs.writeFileSync(p,JSON.stringify(d,null,2));
console.log('daemon.json 已写入 registry-mirrors + live-restore');
EOF2
  echo "！！ 需要执行 systemctl restart docker 使加速生效（live-restore 已开，容器不中断，建议在低峰期执行）"
else
  echo "registry-mirrors 已存在，跳过"
fi

echo "[4/5] 下载并配置 actions runner"
mkdir -p "$RUNNER_DIR" && chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"
sudo -u "$RUNNER_USER" bash <<EOF
set -Eeuo pipefail
cd "$RUNNER_DIR"
if [ ! -f .runner ]; then
  ver=\$(curl -fsSL --connect-timeout 10 --max-time 30 "https://api.github.com/repos/actions/runner/releases/latest" | grep -oP '"tag_name":\s*"v\K[0-9.]+' | head -1)
  ver=\${ver:-2.329.0}
  pkg="actions-runner-linux-x64-\$ver.tar.gz"
  ok=0
  for base in "https://github.com/actions/runner/releases/download/v\$ver" \
              "https://ghproxy.net/https://github.com/actions/runner/releases/download/v\$ver" \
              "https://mirror.ghproxy.com/https://github.com/actions/runner/releases/download/v\$ver"; do
    echo "尝试下载：\$base/\$pkg"
    if curl -fsSL --connect-timeout 15 --max-time 600 -o "\$pkg" "\$base/\$pkg"; then ok=1; break; fi
  done
  [ "\$ok" = 1 ] || { echo "runner 包下载失败（三个源都不通）"; exit 1; }
  tar -xzf "\$pkg" && rm -f "\$pkg"
  ./config.sh --unattended --replace \
    --url "https://github.com/$REPO" \
    --token "$RUNNER_REG_TOKEN" \
    --name "$RUNNER_NAME" \
    --labels "$RUNNER_LABELS" \
    --work "_work" --ephemeral false
else
  echo "runner 已配置过，跳过 config"
fi
EOF

echo "[5/5] 安装 systemd 服务并启动"
cd "$RUNNER_DIR"
./svc.sh install "$RUNNER_USER"
./svc.sh start
./svc.sh status

echo ""
echo "完成。验证：GitHub 仓库 Settings → Actions → Runners 应出现 $RUNNER_NAME（Idle）。"
echo "注意：生产镜像作业 runs-on 为 [self-hosted, linux, x64]，首次构建会拉基础镜像（约几百 MB），之后层缓存常驻，构建+推送降至分钟级。"
