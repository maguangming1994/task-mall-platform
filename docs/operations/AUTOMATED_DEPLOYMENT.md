# 自动部署操作单

本项目采用“本机显式发布 + GitHub CI 门禁 + 服务器受限部署”的流程。任何电脑都可以提交代码；只有 `main` 的前端检查和 API 检查全部通过后，GitHub 才会把该固定提交发送到生产服务器。

## 日常发布

先明确暂存本次修改，不使用无差别的 `git add .`：

```powershell
git add <本次修改的文件>
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Message "本次修改说明"
```

发布脚本会拒绝未暂存文件、`.env`、证书、私钥和数据库备份，随后同步远端状态、运行 `scripts/check.ps1`、提交并推送。推送后由 GitHub Actions 串行部署；CI 或部署失败时不会强制改写 Git 历史。

GitHub 网络连接的已验证处理顺序、当前提交状态和故障记录见 [`GIT_SUBMISSION_RUNBOOK.md`](./GIT_SUBMISSION_RUNBOOK.md)。发布脚本会优先使用当前会话的 `TASK_MALL_GIT_PROXY`；未显式配置时，如果本机 `127.0.0.1:8800` 可用，则直接采用该命令级代理，避免先重复尝试已知不可用的直连路径。

## 首次启用

自动部署默认关闭。完成以下配置并验证后，才把仓库变量 `PRODUCTION_DEPLOY_ENABLED` 设为 `true`：

1. 生成一把仅供 GitHub Actions 使用的 Ed25519 密钥，不复用个人 SSH 密钥。
2. 将公钥加入服务器 `authorized_keys`，使用强制命令限制为 `/usr/local/sbin/task-mall-receive`，同时禁止交互 Shell、PTY 和端口转发。
3. 将 `scripts/server/task-mall-receive.sh` 以 `root:root`、`0755` 安装为 `/usr/local/sbin/task-mall-receive`。
4. 在 GitHub 的 `production` Environment 中设置以下 Secrets：

```text
PRODUCTION_SSH_HOST
PRODUCTION_SSH_PORT
PRODUCTION_SSH_USER
PRODUCTION_SSH_PRIVATE_KEY
PRODUCTION_SSH_KNOWN_HOSTS
```

`PRODUCTION_SSH_KNOWN_HOSTS` 必须来自人工核对过的服务器主机公钥，不能在部署作业中临时信任 `ssh-keyscan` 的结果。服务器密码、应用 `.env`、支付私钥和证书不得保存到 GitHub。

服务器公钥限制格式：

```text
restrict,command="/usr/local/sbin/task-mall-receive" ssh-ed25519 <public-key> task-mall-github-actions
```

服务器继续使用 Compose 项目 `task-mall-production`、环境文件 `/www/wwwroot/task-mall-platform/infra/.env.preview` 和原有数据卷。每个提交解包到 `/www/wwwroot/task-mall-releases/<提交号>`，成功后更新 `/www/wwwroot/task-mall-current` 链接；失败版本不会替换上次成功版本的 Compose 配置。接收脚本拒绝非快进提交和敏感路径，并在迁移前把 PostgreSQL 自定义格式备份保存到 `/www/backups/task-mall`。

### 现有服务器首次接入

首次启用前必须人工核对现有线上目录，不能直接开启 Actions：

1. 记录 `docker compose ls`、六个容器、`task-mall-production_postgres-data` 和 `task-mall-production_redis-data`，确认继续使用同一个 Compose 项目和数据卷。
2. 逐项比较线上 `.gitignore`、`infra/compose.production.yaml`、`infra/docker/api.Dockerfile` 等本地修改与 `main` 并保存差异。自动发布使用独立 worktree，不要求盲目清理现有工作区，也不要执行 `git reset --hard`。
3. 确认 `infra/.env.preview` 继续由 `.gitignore` 排除且没有进入任何 Git 提交；长期应迁移到 `/etc/task-mall/production.env` 等仓库外路径。`WECHAT_PAYMENT_PRIVATE_KEY_FILE` 和 `WECHAT_PAYMENT_PLATFORM_CERT_FILE` 必须指向仓库外真实存在的绝对路径。
4. 从当前运行中的 `api`、`nginx` 容器读取并人工核对镜像 ID，把它们分别标记为 `task-mall-api:<当前提交号>`、`task-mall-web:<当前提交号>`。首次接入时不要预先创建 `.git/task-mall-production-release`；第一次成功发布会同时创建独立 release worktree 和该记录。
5. 用现有容器镜像和数据库备份做一次人工恢复演练，确认移动端、管理后台、API readiness 和公网 HTTPS 后，再启用仓库变量。

## 权限边界

能够修改 `main` 中的 Workflow、Compose、Dockerfile 或生产部署脚本，实际上就拥有生产代码执行能力。启用前应保护 `main`，禁止强制推送，限制直接推送者，并为 `production` Environment 配置最少管理员和人工审批；部署密钥只用于本仓库并定期轮换。若当前 GitHub 套餐不支持这些保护，自动部署变量应保持关闭，改用人工审批发布。

## 停止自动部署

出现事故或维护窗口时，将仓库变量 `PRODUCTION_DEPLOY_ENABLED` 设为 `false`。这只停止后续自动发布，不会停止当前线上容器，也不会删除数据库、数据卷、镜像或备份。

## 失败处理

- CI 失败：生产环境保持原版本，修复后重新提交。
- 构建或迁移失败：部署停止；数据库迁移不自动反向回滚。
- 新版本健康检查失败：存在上一版带提交号的镜像时恢复上一版应用，数据库保持前向兼容状态。
- 每次成功发布会检查六个 Compose 服务、API readiness、移动端、管理后台和公网 HTTPS 地址。
- 接收脚本要求 `/www` 和 Docker 数据目录各有至少 5 GiB 可用空间。备份和带提交号镜像不会自动删除；应配置磁盘告警并按已验证的保留策略人工清理，禁止使用无范围的 `docker system prune`。
