# I1 生产接入与真实联调清单

## 当前结论

第 9 批不依赖真实账号的代码已完成：微信真实适配器、短信 Webhook、外部文件扫描 Webhook、失败关闭策略、后台上线准备页和严格 CLI 门禁均已具备。真实渠道凭据不得写入仓库、文档、工单截图或前端构建产物，只能通过部署平台的密钥管理注入。

本地开发不会因缺少真实凭据停止；生产发布必须执行：

```bash
php artisan production:readiness --strict
```

任一必需项未通过时命令返回非零退出码，发布流水线应立即停止。

## 需要用户或运营方一次性提供的外部资料

| 类别 | 所需资料 | 用途 |
|---|---|---|
| 微信小程序 | AppID、AppSecret、合法请求/业务/上传/下载域名 | 小程序登录、接口调用和审核 |
| 微信公众号 | AppID、AppSecret、网页授权域名 | 微信内 H5 OAuth 登录 |
| 浏览器 H5 | 正式 HTTPS 域名、ICP备案与证书 | 微信外浏览器访问 |
| 短信 | 已审核签名、验证码模板 ID、网关端点和令牌 | 手机登录与绑定 |
| 文件扫描 | 扫描服务端点、鉴权令牌、生产存储访问策略 | 上传文件异步安全扫描 |
| 主体资料 | 运营主体全称、证照、注册地址、客服和隐私联系人 | 正式协议、备案和平台审核 |
| 法务文本 | 用户协议、隐私政策、第三方共享/SDK/权限清单及批准记录 | 正式发布与隐私合规 |

## 环境变量

- 微信：`WECHAT_AUTH_DRIVER=wechat`、`WECHAT_MINI_APP_ID`、`WECHAT_MINI_APP_SECRET`、`WECHAT_OA_APP_ID`、`WECHAT_OA_APP_SECRET`、`WECHAT_AUTHORIZED_DOMAINS`。
- 短信：`PHONE_VERIFICATION_DRIVER=webhook`、`SMS_WEBHOOK_ENDPOINT`、`SMS_WEBHOOK_TOKEN`、`SMS_TEMPLATE_ID`、`SMS_SIGN_NAME`。
- 上传：`UPLOAD_MODE=s3_presigned`、`UPLOAD_DISK=s3`、`UPLOAD_SCANNER=webhook`、`UPLOAD_SCANNER_ENDPOINT`、`UPLOAD_SCANNER_TOKEN`。
- 主体与法务：`OPERATOR_ENTITY_NAME`、`CUSTOMER_SERVICE_CONTACT`、`PRIVACY_CONTACT`、`LEGAL_DOCUMENTS_APPROVED=true`。
- 安全：用户端和后台的 JWT/摘要密钥必须分别生成且互不相同，生产环境关闭 `APP_DEBUG`。

## 真实联调证据

完成联调时保存以下不含密钥的证据：小程序与公众号登录时间/请求 ID、三端账号归一结果、短信送达记录、恶意样本拒绝记录、正式协议版本号、真机型号与微信版本、失败与降级截图、监控告警以及回滚演练结果。

本文件是工程验收清单，不替代平台审核意见或正式法律意见。
