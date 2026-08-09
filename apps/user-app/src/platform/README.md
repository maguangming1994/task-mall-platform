# 多端平台适配层

这里统一封装微信小程序、微信内 H5 和微信外 H5 的运行环境、登录、分享、支付与消息差异。

计划模块：

- `runtime`：运行环境识别
- `capabilities`：本地能力和服务端能力合并
- `auth`：微信与手机号登录
- `payment`：小程序、JSAPI、MWEB、余额支付
- `share`：小程序卡片、微信分享、系统分享和复制链接
- `notification`：订阅授权和消息入口

业务页面不得直接调用平台私有 API。详细规则见 [`docs/architecture/MULTI_PLATFORM_ADAPTERS.md`](../../../../docs/architecture/MULTI_PLATFORM_ADAPTERS.md)。
