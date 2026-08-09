# API Contracts

存放由 OpenAPI 生成或人工维护的跨应用接口契约。避免用户端、管理后台和服务端重复定义字段及状态枚举。

- `openapi.yaml`：当前唯一 API 契约来源

框架初始化后，根据该文件生成 TypeScript 客户端类型，并在 CI 中执行 OpenAPI 校验和破坏性变更检查。
