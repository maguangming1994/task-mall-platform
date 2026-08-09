# Scripts

## 本地演示数据

执行 `powershell -ExecutionPolicy Bypass -File scripts/seed-demo.ps1` 会以幂等方式生成本地预览管理员、体验用户、任务、商品、库存、社区内容和用户资产。脚本只允许在 `local` 或 `testing` 环境运行，不会向生产环境写入演示数据。

- `bootstrap.ps1`：创建本地环境文件、安装前后端依赖并生成 Laravel APP_KEY。
- `dev-infra.ps1`：启动并等待 PostgreSQL、Redis、MinIO 健康。
- `stop-infra.ps1`：停止本地基础服务，不删除数据卷。
- `check.ps1`：执行类型检查、Lint、H5/微信小程序/后台构建、PHP 测试和 Pint。

PowerShell 脚本可用 `powershell -ExecutionPolicy Bypass -File scripts/<name>.ps1` 执行。
