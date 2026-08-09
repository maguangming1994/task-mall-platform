# 基础设施

本地 I0 基础服务使用 `compose.yaml`：

- PostgreSQL：`127.0.0.1:5432`
- Redis：`127.0.0.1:6379`
- MinIO S3 API：`http://127.0.0.1:9000`
- MinIO 控制台：`http://127.0.0.1:9001`
- MinIO 启动时会幂等创建本地桶：`task-mall-local`

首次使用可复制 `.env.example` 为 `.env`，然后在项目根目录执行 `powershell -ExecutionPolicy Bypass -File scripts/dev-infra.ps1`。停止服务执行 `scripts/stop-infra.ps1`；该操作不会删除数据卷。

这里的账号只用于本地开发，不得复用到测试或生产。

生产容器使用 `compose.production.yaml`、`docker/api.Dockerfile`、`docker/web.Dockerfile` 和 `nginx/task-mall.conf`。生产配置从未提交的 `.env.production` 读取，API、队列和调度使用同一不可变镜像；Nginx 在反向代理后监听 8080，数据库和 Redis 不暴露宿主机端口。上线、灰度、监控、备份和回滚步骤见 `docs/operations/DEPLOYMENT_RUNBOOK.md`。
