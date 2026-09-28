# 任务商城系统

面向微信小程序、H5、Android 和 iOS 的任务激励与商城一体化系统。

## 应用组成

- `apps/user-app`：uni-app 用户端（Vue 3 + TypeScript + Pinia）
- `apps/admin-web`：React 管理后台（TypeScript + Ant Design）
- `apps/api`：Laravel API 服务端
- `packages`：跨应用共享的类型、接口契约和规范
- `design`：UI 设计稿、原型图、设计规范与交付资源
- `infra`：Docker、Nginx 与部署配置
- `docs`：架构、接口、数据库和业务文档
- `xmind`：项目总览思维导图及 XMind 结构化源文件
- `scripts`：本地开发、检查与部署辅助脚本

## 当前状态

需求基线 v1.0、I0、I1 与第 5 至第 30 批本地实现已经全部完成，覆盖治理基础、任务资产、商城订单、支付售后、社区、通知运营、安全韧性、UAT 和部署运维。真实渠道、真机与生产发布等待正式凭据和授权，进度见 [`docs/project/CONTINUOUS_DELIVERY_RUNBOOK.md`](docs/project/CONTINUOUS_DELIVERY_RUNBOOK.md)。

产品化第一阶段已经完成 V1 视觉系统、用户端五栏导航、运营工作台和幂等演示数据；最新本地 UAT 为 `product-preview-v1 / passed_local`，详细状态见 [`docs/project/PRODUCTIZATION_STATUS.md`](docs/project/PRODUCTIZATION_STATUS.md)。

产品原型、UI、图标和设计验收按照 [`design/DESIGN_PLAN.md`](design/DESIGN_PLAN.md) 执行，页面通过设计门槛后再进入正式开发。

项目从需求到上线的完整阶段、交付物和验收门槛按照 [`docs/PROJECT_ROADMAP.md`](docs/PROJECT_ROADMAP.md) 执行。

冻结范围见 [`docs/project/REQUIREMENTS_BASELINE_V1.md`](docs/project/REQUIREMENTS_BASELINE_V1.md)，迭代顺序与 DoR/DoD 见 [`docs/project/DEVELOPMENT_EXECUTION_PLAN.md`](docs/project/DEVELOPMENT_EXECUTION_PLAN.md)。

项目可视化总览见 [`xmind/任务商城系统-项目总览.xmind`](xmind/任务商城系统-项目总览.xmind)。

## 目录约定

生产发布使用 GitHub Actions 构建腾讯云 TCR 个人版私有镜像，服务器只拉取和切换。首次仓库登录、凭据配置、失败处理和验收步骤见 [TCR 发布说明](docs/operations/PRODUCTION_TCR.md)。

- 用户端的平台差异统一放在 `apps/user-app/src/platform`。
- 服务端业务按领域放在 `apps/api/app/Modules`。
- 积分、库存、订单和支付的状态变更必须保留流水，并通过事务及幂等机制处理。
- API 统一使用 `/api/v1` 前缀。

## I0 本地启动

已锁定 Node.js 24.19.0、pnpm 11.9.0、PHP 8.5 和 Laravel 13。首次运行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/bootstrap.ps1
powershell -ExecutionPolicy Bypass -File scripts/dev-infra.ps1
cd apps/api
php artisan migrate
php artisan serve
```

另开终端启动用户 H5 或管理后台：

```powershell
pnpm.cmd run dev:user:h5
pnpm.cmd run dev:admin
```

微信小程序构建使用 `pnpm.cmd run build:user:wechat`，完整检查使用 `powershell -ExecutionPolicy Bypass -File scripts/check.ps1`。API 健康检查为 `http://127.0.0.1:8000/api/v1/health`。

首次创建后台账号时先执行迁移和 RBAC 种子，再以交互方式输入密码：

```powershell
cd apps/api
php artisan migrate --seed
php artisan admin:create first-admin --role=super_admin --name="首位管理员"
```

项目不会自动创建默认后台账号或通用密码。
