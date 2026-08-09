# I0 项目初始化验收记录

## 1. 结论

I0“基线与环境”已于 2026-08-04 完成。本地开发环境、三个可运行应用、依赖锁、基础服务、健康检查、数据库迁移、检查脚本和 CI 基线均已建立，项目可以进入 I1“认证与治理”。

## 2. 已锁定工具与框架

| 项目 | 版本/实现 |
|---|---|
| Node.js | 24.19.0 LTS |
| npm / pnpm | 11.17.0 / 11.9.0 |
| PHP | 8.5.8 |
| Composer | 2.10.2 |
| Laravel | 13.23.0 |
| 用户端 | uni-app Vue 3 + TypeScript + Pinia |
| 管理端 | React 19 + TypeScript + Ant Design 6 |
| Docker Desktop / Compose | 4.85.0 / 5.3.1 |
| 本地数据服务 | PostgreSQL 17、Redis 7.4、MinIO |

版本约束记录在根目录的 `.nvmrc`、`.node-version`、`.tool-versions`、`package.json`、`pnpm-lock.yaml` 和 `apps/api/composer.lock` 中；容器版本记录在 `infra/compose.yaml` 中。

## 3. 已交付内容

- `apps/user-app`：uni-app 用户端，可构建微信公众号 H5 与微信小程序。
- `apps/admin-web`：React 管理后台，已配置 Ant Design 和本地 API 代理。
- `apps/api`：Laravel API，统一 `/api/v1` 前缀并提供 `/api/v1/health`。
- `infra/compose.yaml`：PostgreSQL、Redis、MinIO 及本地对象存储桶初始化。
- `scripts/bootstrap.ps1`：安装项目依赖、生成环境文件和 Laravel 应用密钥。
- `scripts/dev-infra.ps1` / `stop-infra.ps1`：启动或停止本地基础服务，停止不会删除数据卷。
- `scripts/check.ps1`：类型检查、代码检查、前端构建、PHP 测试和格式检查。
- `.github/workflows/ci.yml`：前端与服务端 CI 基线。

## 4. 实际验收结果

| 检查项 | 结果 |
|---|---|
| pnpm 安装与依赖锁 | 通过，peer dependency 问题为 0 |
| 用户端 TypeScript 检查 | 通过 |
| 管理端 TypeScript 与代码检查 | 通过 |
| 用户端 H5 构建 | 通过 |
| 用户端微信小程序构建 | 通过 |
| 管理端生产构建 | 通过 |
| Laravel Pint | 通过 |
| Laravel 测试 | 2 项通过，5 个断言 |
| `/api/v1/health` 路由 | 已注册并由 Feature Test 验证 |
| Composer 配置与安全审计 | 通过，无已知安全公告 |
| Docker Compose 配置 | 通过 |
| PostgreSQL / Redis / MinIO 健康检查 | 全部通过 |
| Laravel 首次 PostgreSQL 迁移 | 通过 |
| Redis 写入与读取 | 通过 |
| MinIO 建桶、写入、读取与删除 | 通过 |

管理端构建目前有一个非阻断的包体积提示（主 JavaScript 约 552 kB，gzip 约 180 kB）。I1 开始形成页面路由时，应按页面做动态加载和代码分包。

## 5. 本地启动

```powershell
powershell -ExecutionPolicy Bypass -File scripts/bootstrap.ps1
powershell -ExecutionPolicy Bypass -File scripts/dev-infra.ps1
cd apps/api
php artisan migrate
php artisan serve
```

另开终端运行：

```powershell
pnpm.cmd run dev:user:h5
pnpm.cmd run dev:admin
```

微信小程序构建命令为 `pnpm.cmd run build:user:wechat`，完整本地检查命令为 `powershell -ExecutionPolicy Bypass -File scripts/check.ps1`。

## 6. I1 开始前的就绪清单

- 明确产品、UI、前端、PHP、测试和验收负责人。
- 完成登录、绑定、会话设备、后台权限和配置发布的页面状态与原型。
- 把认证、RBAC、审计、配置中心和上传接口细化到可开发 OpenAPI。
- 确认微信小程序与公众号测试账号、回调域名及本地联调替代方案。
- 为 I1 建立验收场景、测试数据、数据权限与异常路径。

