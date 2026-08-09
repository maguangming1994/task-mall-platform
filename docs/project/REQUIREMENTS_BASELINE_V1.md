# 需求基线 v1.0

## 1. 基线状态

| 项目 | 内容 |
|---|---|
| 基线版本 | v1.0 |
| 冻结日期 | 2026-08-04 |
| 状态 | 已冻结，可进入可运行项目初始化 |
| 首发端 | 微信小程序、微信内 H5、微信外通用 H5、管理后台 |
| 用户端技术 | uni-app + Vue 3 + TypeScript + Pinia |
| 服务端技术 | PHP + Laravel 模块化单体 |
| 管理端技术 | React + TypeScript + Ant Design |

本基线汇总第一至第三十二组已确认结论。后续补充真实主体资料、品牌素材、团队姓名、容量数值和第三方生产凭证属于实施输入，不自动改变已确认业务范围。

## 2. P0 产品闭环

P0 必须跑通：登录/统一身份 → 任务参与与审核/自动核验 → 积分、优惠券或余额奖励 → 三种商城购买方式 → 余额全额或微信全额现金支付 → 履约 → 售后退款/退货/换货 → 全资产对账。

范围明细以以下文档为准：

- [`../product/MVP_SCOPE.md`](../product/MVP_SCOPE.md)
- [`../product/REQUIREMENTS_CHECKLIST.md`](../product/REQUIREMENTS_CHECKLIST.md)
- [`../business/TASK_RULES.md`](../business/TASK_RULES.md)
- [`../business/PRODUCT_INVENTORY_ORDER_RULES.md`](../business/PRODUCT_INVENTORY_ORDER_RULES.md)
- [`../business/REFUND_AND_COUPON_RULES.md`](../business/REFUND_AND_COUPON_RULES.md)
- [`../../packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml)

## 3. 优先级

- P0：首发闭环、资产准确、权限安全、合规阻断和可发布能力。P0 未完成不得以 P1/P2 替代。
- P1：运营效率、后台批量处理、报表增强和非阻断体验优化；只在不拖延 P0 时进入迭代。
- P2：智能推荐、复杂营销、原生 App、多商户和外部发布等后续增强，当前基线不实施。

## 4. 已确认的质量与发布门槛

- 核心 API 正常目标负载 p95 不高于 500ms，月可用性目标 99.9%。
- 初始 RPO 不高于 15 分钟、RTO 不高于 2 小时，资源方案需结合预算复核。
- P0/P1 缺陷为零，核心 E2E 全过，无越权、超卖、重复入账和账务差异。
- 数据库迁移、备份恢复、灰度、回滚、对账和事故处置完成演练。
- 小程序、微信内/外 H5 及后台目标浏览器通过设计与真机走查。

## 5. 未冻结的实施输入

以下项目必须在对应迭代 DoR 前补齐，但不是重新开放业务需求讨论：

- 产品、UI、前端、PHP、测试、运维、运营、财务、安全和业务验收负责人姓名。
- 品牌名称、Logo、正式品牌色和参考产品。
- 预计 DAU、峰值在线、瞬时领任务人数、日订单量、读写 RPS 和文件规模。
- 云厂商、服务器区域、域名、对象存储、短信、物流、客服和监控供应商。
- 运营主体、AppID、商户号、备案、支付权限、正式协议和法务/财务签字。

## 6. 基线变更

任何新增、删除或改变已确认语义的需求都必须使用 [`CHANGE_REQUEST_TEMPLATE.md`](CHANGE_REQUEST_TEMPLATE.md)，完成产品收益、设计、接口、数据、账务、合规、测试、运维和发布日期影响评估。批准的变更生成新基线版本或明确进入后续版本；不得直接修改 v1.0 历史结论。
