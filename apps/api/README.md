# API 服务端

基于 Laravel 的模块化单体服务，为用户端和管理后台提供 `/api/v1` 接口。

## 目录职责

- `app/Common`：跨模块基础能力
- `app/Modules`：领域模块
- `config`：应用和第三方服务配置
- `database`：迁移、数据工厂和种子
- `routes`：用户端、管理端和回调路由
- `storage`：运行时文件
- `tests`：功能、单元和集成测试

## I1 已实现接口

- `POST /api/v1/auth/phone/verification-codes`：申请手机号验证码。
- `POST /api/v1/auth/phone/login`：手机号登录或创建统一用户。
- `POST /api/v1/auth/refresh`：轮换 Refresh Token 并签发新令牌对。
- `POST /api/v1/auth/logout`：退出当前会话或全部设备。
- `GET /api/v1/me`：读取当前用户。

本地和测试环境使用日志验证码适配器，默认验证码为 `246810`；非本地环境会拒绝日志适配器，接入真实短信供应商前不能用于生产。微信小程序和公众号登录入口已保留在 OpenAPI，真实凭据确认后通过适配器实现。
