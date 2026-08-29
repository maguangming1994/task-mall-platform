# Git 提交与网络处理记录

## 当前状态

- 记录日期：2026-08-29
- 本地分支：`main`
- 本地与远端提交：`272f27dd53424ae9ee59f41787ca6c4d95fac909`
- 线上生产版本：`272f27dd53424ae9ee59f41787ca6c4d95fac909`
- 提交说明：`fix: 统一微信账户绑定状态`
- 本地仍有 4 个未提交的图片改动，属于用户原有工作，本次提交没有暂存或覆盖。

## 本次问题

向 GitHub 推送时，直连 `github.com:443` 连续失败。代码、提交和远端仓库本身没有异常，问题位于当前电脑到 GitHub 的直连网络路径。继续重复执行相同的直连命令不会改善结果，只会增加等待时间。

本机 QuickQ 的 HTTP 代理监听在 `127.0.0.1:8800`。使用 Git 命令级代理后，首次推送成功：

```powershell
git -c http.proxy=http://127.0.0.1:8800 -c https.proxy=http://127.0.0.1:8800 push origin main
```

该方式只影响当前命令，不修改 Git 全局配置，也不改变 Windows 系统代理。

## 后续优先方案

统一使用发布脚本提交，不直接反复执行 `git push`：

```powershell
git add <本次需要提交的文件>
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Message "本次修改说明"
```

`scripts/release.ps1` 的网络顺序如下：

1. 若设置了 `TASK_MALL_GIT_PROXY`，直接将该地址用于本次 `fetch` 和 `push`。
2. 否则通过本机 `127.0.0.1:8800` 发起一次到 `github.com:443` 的 HTTP CONNECT 和 TLS 预检；3 秒内确认安全隧道可用时，优先使用已验证的 HTTP 代理。
3. QuickQ 正在运行但代理端口不可用时立即停止，提示先恢复 QuickQ 连接，不再重复尝试已知失败的直连路径。
4. 自动补齐 Codex MinGit 的 `mingw64/bin` 运行路径，确保 `git-remote-https` 可被调用。
5. 推送成功后再次获取 `origin/main`，核对远端提交必须等于本次提交。
6. 生产部署继续由 GitHub Actions 执行，不绕过 CI 手工覆盖线上环境。

其他电脑的代理地址不同时，可在当前 PowerShell 会话显式指定：

```powershell
$env:TASK_MALL_GIT_PROXY = 'http://127.0.0.1:<代理端口>'
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Message "本次修改说明"
```

明确需要绕过本地代理并只尝试一次直连时，可在当前会话设置：

```powershell
$env:TASK_MALL_GIT_PROXY = 'direct'
```

## 发布后核对

1. `git rev-parse HEAD` 与 `git rev-parse origin/main` 一致。
2. GitHub Actions 的检查和生产部署完成。
3. 服务器 `.git/task-mall-production-release` 等于本次提交号。
4. 移动端、`/admin/` 和 `/api/v1/health/ready` 均正常。
