# 用户端

基于 uni-app、Vue 3、TypeScript 和 Pinia，目标平台为微信小程序、H5、Android 和 iOS。

## 目录职责

- `src/api`：后端接口定义与请求封装
- `src/components`：跨页面通用组件
- `src/composables`：可复用组合式逻辑
- `src/pages`：主包页面
- `src/pages-task`：任务大厅、任务详情、领取/提交与我的任务分包
- `src/pages-task-publisher`：仅平台管理员可用的前端任务发布中心、草稿、预览和发布申请
- `src/pages-mall`：商城分包
- `src/pages-order`：订单分包
- `src/pages-user`：用户中心分包
- `src/pages-wallet`：余额、钱包流水和后续支付/提现能力
- `src/pages-growth`：签到、邀请和新手成长入口；任务状态统一复用任务模块
- `src/pages-community`：帖子、评论和社区分包
- `src/platform`：登录、支付、分享、上传等多端适配
- `src/stores`：Pinia 状态
- `src/types`：用户端类型
- `src/utils`：通用工具

首发端能力矩阵和降级规则见 [`docs/architecture/MULTI_PLATFORM_ADAPTERS.md`](../../docs/architecture/MULTI_PLATFORM_ADAPTERS.md)。微信外 H5 保持可访问；H5 支付不可用时允许余额足额支付，否则引导在微信中打开。

I1 已接入手机号登录页、验证码申请、Token/Refresh Token 保存与轮换、当前用户恢复和安全退出。H5 开发服务器通过 `/api` 代理访问本地 Laravel；微信开发者工具默认访问 `http://127.0.0.1:8000/api/v1`，真机联调时通过 `VITE_API_BASE_URL` 指向可访问的 HTTPS 测试域名。
