# API 错误码

## 通用

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 400 | `REQUEST_INVALID` | 请求格式错误 |
| 401 | `AUTH_REQUIRED` | 未登录或 Token 失效 |
| 401 | `AUTH_SESSION_REVOKED` | 当前会话已退出、被移除或因安全原因撤销 |
| 403 | `ACCOUNT_DISABLED` | 账号已禁用、注销或当前不允许登录 |
| 403 | `PERMISSION_DENIED` | 无权限 |
| 404 | `RESOURCE_NOT_FOUND` | 资源不存在 |
| 409 | `RESOURCE_VERSION_CONFLICT` | 并发版本冲突 |
| 409 | `IDEMPOTENCY_KEY_REUSED` | 相同幂等键对应不同请求 |
| 409 | `IDENTITY_ALREADY_BOUND` | 手机号或微信身份已绑定到其他账号，需人工处理 |
| 409 | `ACCOUNT_CANCELLATION_BLOCKED` | 存在未结业务、冻结资产或非零余额，暂不能注销 |
| 409 | `ACCOUNT_CANCELLATION_STATE_INVALID` | 当前注销申请状态不允许此操作 |
| 409 | `PRIVACY_REQUEST_ALREADY_PENDING` | 同类个人信息权利请求正在处理中 |
| 409 | `PRIVACY_DELETION_BLOCKED` | 存在未结业务、争议、法定留存期限或法律冻结，暂不能删除全部数据 |
| 403 | `LEGAL_DOCUMENT_CONSENT_REQUIRED` | 当前功能需要先接受生效的必要协议版本 |
| 409 | `LEGAL_CONSENT_WITHDRAWAL_RESTRICTS_SERVICE` | 撤回必要协议将限制对应服务，需完成明确确认 |
| 409 | `LEGAL_DOCUMENT_STATE_INVALID` | 当前协议版本状态不允许发布或执行该操作 |
| 422 | `VALIDATION_FAILED` | 字段校验失败 |
| 422 | `PHONE_VERIFICATION_FAILED` | 手机验证码或换绑身份校验失败 |
| 422 | `PHONE_CHANGE_REQUIRES_CURRENT_VERIFICATION` | 已绑定手机号变更时缺少当前手机号验证 |
| 422 | `WECHAT_AUTH_FAILED` | 微信临时登录凭证无效、过期或已使用 |
| 409 | `IDENTITY_MERGE_REQUIRED` | 微信或手机号身份已关联其他账号，已进入人工身份处理 |
| 429 | `RATE_LIMITED` | 请求过于频繁 |
| 503 | `PHONE_VERIFICATION_PROVIDER_UNAVAILABLE` | 手机验证码服务暂不可用 |
| 503 | `WECHAT_PROVIDER_UNAVAILABLE` | 微信凭据未配置、网络异常或微信服务暂不可用 |
| 500 | `INTERNAL_ERROR` | 未知服务端错误 |

## 管理后台认证与权限

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 401 | `ADMIN_AUTH_REQUIRED` | 未提供有效的后台 Access Token；普通用户 Token 不能替代 |
| 401 | `ADMIN_AUTH_INVALID_CREDENTIALS` | 后台账号或密码错误 |
| 401 | `ADMIN_AUTH_SESSION_REVOKED` | 后台会话已退出、空闲超时、达到最大期限或被安全策略撤销 |
| 403 | `ADMIN_ACCOUNT_DISABLED` | 后台账号已停用 |
| 403 | `ADMIN_MFA_REQUIRED` | 该后台账号需要完成二次验证 |
| 403 | `ADMIN_PERMISSION_DENIED` | 当前后台账号缺少服务端要求的权限 |
| 429 | `ADMIN_AUTH_LOCKED` | 同一后台账号与 IP 组合连续失败，已被临时锁定 |

## 任务与社区

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 409 | `TASK_NOT_CLAIMABLE` | 任务当前不能领取 |
| 409 | `TASK_QUOTA_EXHAUSTED` | 名额已满 |
| 409 | `TASK_ALREADY_CLAIMED` | 已领取且不允许重复领取 |
| 409 | `TASK_CLAIM_REENTRY_NOT_ALLOWED` | 当前任务周期已经领取或取消，首版不允许重新领取 |
| 409 | `TASK_CLAIM_CANCEL_NOT_ALLOWED` | 已提交、正在审核/申诉或已有自动核验进度，不能取消 |
| 409 | `TASK_COMPLETION_DEADLINE_EXPIRED` | 用户任务实际完成期限已过 |
| 404 | `TASK_NOT_VISIBLE` | 任务不存在或当前访问者无权看到定向保密任务 |
| 409 | `TASK_PLACEMENT_REQUEST_PENDING` | 当前任务与栏目已有进行中的推荐位申请 |
| 409 | `TASK_PLACEMENT_SELF_REVIEW_NOT_ALLOWED` | 发布者不能审批自己的推荐位申请 |
| 409 | `TASK_MAJOR_CHANGE_APPROVAL_REQUIRED` | 重大修改已生成新版本并等待必要审批 |
| 409 | `TASK_QUOTA_REDUCTION_EXCEEDED` | 新名额低于当前占位数加成功奖励数 |
| 409 | `TASK_CLOSE_APPROVAL_REQUIRED` | 存在进行中用户，关闭任务需要后台审批及处理策略 |
| 409 | `TASK_CLOSED_NOT_REOPENABLE` | 已关闭任务不能恢复，请复制成新任务 |
| 409 | `TASK_ARCHIVE_SETTLEMENT_PENDING` | 审核、申诉、奖励、预算或投诉尚未结清，不能归档 |
| 409 | `TASK_SUBMISSION_CLOSED` | 已超过提交时间 |
| 409 | `TASK_SUBMISSION_LIMIT_REACHED` | 已达到任务提交次数上限 |
| 409 | `TASK_SUBMISSION_REVIEW_STARTED` | 审核已经开始，不能撤回或覆盖提交 |
| 409 | `TASK_APPEAL_EXPIRED` | 已超过任务申诉期限 |
| 409 | `TASK_APPEAL_LIMIT_REACHED` | 已达到任务申诉次数上限 |
| 409 | `TASK_APPEAL_REVIEWER_CONFLICT` | 申诉审核人与原审核人相同 |
| 409 | `TASK_REWARD_DELIVERY_PENDING` | 奖励仍在发放或重试中 |
| 409 | `TASK_ALREADY_REWARDED` | 已发放奖励 |
| 403 | `TASK_PUBLISHER_PERMISSION_REQUIRED` | 当前账号不是可用的平台管理员或缺少前端任务发布权限 |
| 401 | `TASK_PUBLISHER_SESSION_EXPIRED` | 前端任务发布会话已过期或被撤销 |
| 409 | `TASK_PUBLISH_APPROVAL_REQUIRED` | 任务已提交并等待必要审批，暂不能发布或继续编辑 |
| 409 | `TASK_PUBLISH_REQUEST_PENDING` | 当前任务已有进行中的发布申请 |
| 422 | `TASK_PUBLISH_VALIDATION_FAILED` | 时间、预算、资格、核验事件或材料配置未通过发布校验 |
| 409 | `TASK_BUDGET_INSUFFICIENT` | 一个或多个奖励预算池不足，不能发布或新增参与用户 |
| 409 | `TASK_REWARD_QUOTA_EXHAUSTED` | 任务总奖励次数或当前周期奖励次数已用尽 |
| 409 | `TASK_BUDGET_REDUCTION_EXCEEDED` | 预算减少后将低于已预留和已发放值 |
| 409 | `TASK_BUDGET_SETTLEMENT_PENDING` | 仍有审核、申诉或奖励重试，暂不能释放任务剩余预算 |
| 409 | `TASK_REPORT_ALREADY_EXISTS` | 当前用户已对该任务提交过有效举报 |
| 409 | `TASK_RISK_REVIEW_REQUIRED` | 任务、成果或发布者触发人工风控审核，暂不能继续当前动作 |
| 409 | `TASK_REWARD_FROZEN_BY_RISK` | 奖励因风险案件被冻结，需等待人工结论 |
| 422 | `TASK_EXTERNAL_DOMAIN_NOT_ALLOWED` | 任务包含未进入允许名单的外部链接或域名 |
| 422 | `TASK_SENSITIVE_DATA_COLLECTION_NOT_ALLOWED` | 任务要求收集首版禁止的高敏感个人信息 |
| 403 | `TASK_PUBLISHER_RESTRICTED` | 发布主体处于暂停、限额或权限撤销状态 |
| 403 | `TASK_EXTERNAL_PUBLISHING_DISABLED` | 普通用户与机构任务发布尚未开放或当前灰度阶段不包含该主体 |
| 409 | `TASK_PUBLISHER_QUALIFICATION_REQUIRED` | 发布主体认证、资质或复审未满足当前任务类别要求 |
| 409 | `TASK_PUBLISHER_LIMIT_EXCEEDED` | 在线任务数、参与人数、奖励或预算超过发布者当前等级额度 |
| 409 | `TASK_PUBLISHER_FUNDS_INSUFFICIENT` | 发布者预付奖励资金不足，无法锁定完整任务预算 |
| 409 | `TASK_RISK_APPEAL_ALREADY_EXISTS` | 当前风险案件已有有效申诉 |
| 413 | `TASK_ATTACHMENT_LIMIT_EXCEEDED` | 附件数量或大小超过任务快照限制 |
| 422 | `CONTENT_REJECTED` | 内容未通过规则检查 |
| 429 | `POINT_REWARD_LIMIT_REACHED` | 已达到该来源奖励上限 |
| 409 | `CONTENT_APPEAL_EXPIRED` | 已超过内容申诉期限 |
| 409 | `CONTENT_APPEAL_ALREADY_EXISTS` | 已存在有效申诉 |
| 409 | `CONTENT_REPORT_ALREADY_EXISTS` | 已举报该内容 |
| 403 | `COMMUNITY_POSTING_RESTRICTED` | 当前账号被限制发帖或评论 |

## 积分与优惠券

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 409 | `POINTS_INSUFFICIENT` | 可用积分不足 |
| 409 | `POINTS_LOT_EXPIRED` | 指定积分批次已过期 |
| 409 | `POINT_DEBT_OUTSTANDING` | 存在未偿还积分欠额，暂不能消费或冻结积分 |
| 409 | `POINT_DEBT_WAIVER_EXCEEDED` | 减免数量超过当前积分欠额 |
| 403 | `POINT_TRANSFER_NOT_ALLOWED` | 积分不允许转账、提现或兑换余额 |
| 409 | `COUPON_NOT_AVAILABLE` | 优惠券不可用 |
| 409 | `COUPON_REQUIREMENT_NOT_MET` | 未满足使用门槛 |
| 409 | `COUPON_ALREADY_LOCKED` | 优惠券已被其他订单锁定 |
| 422 | `COUPON_STACKING_NOT_ALLOWED` | 不允许叠加优惠券 |

## 用户余额

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 409 | `WALLET_BALANCE_INSUFFICIENT` | 可用余额不足 |
| 409 | `WALLET_FULL_PAYMENT_REQUIRED` | 余额不足以全额支付现金应付，请改用微信支付 |
| 422 | `CASH_PAYMENT_METHOD_CONFLICT` | 同一订单不允许余额与微信组合支付 |
| 409 | `WALLET_TRANSACTION_DUPLICATED` | 余额流水已处理 |
| 409 | `WALLET_ADJUSTMENT_APPROVAL_REQUIRED` | 人工调整需要二次审核 |
| 409 | `WALLET_FROZEN_BALANCE_EXISTS` | 冻结余额存在，不能执行清零 |
| 409 | `WITHDRAWAL_LIMIT_EXCEEDED` | 提现金额或次数超过后台限制 |
| 409 | `WITHDRAWAL_STATUS_INVALID` | 当前提现状态不允许操作 |
| 502 | `WITHDRAWAL_PROVIDER_ERROR` | 提现渠道处理异常 |

## 商品、订单与支付

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 409 | `PRODUCT_NOT_AVAILABLE` | 商品或 SKU 不可售 |
| 409 | `PURCHASE_MODE_NOT_AVAILABLE` | 购买方式未开启 |
| 409 | `ORDER_STOCK_INSUFFICIENT` | 库存不足 |
| 409 | `INVENTORY_RESERVATION_EXPIRED` | 库存预占已过期，需重新结算 |
| 409 | `PURCHASE_LIMIT_EXCEEDED` | 超过单笔、每日、活动期或终身限购 |
| 409 | `CHECKOUT_GROUP_INVALID` | 交付分组、地址、优惠券或支付方式与商品不匹配 |
| 409 | `SHIPPING_ADDRESS_UNSUPPORTED` | 收货地址不在商品可配送区域 |
| 409 | `SHIPPING_RULE_CHANGED` | 运费模板、区域或包邮规则已变化，需重新结算 |
| 409 | `ORDER_STATUS_INVALID` | 当前订单状态不允许操作 |
| 409 | `ORDER_PAYMENT_EXPIRED` | 待支付订单已超时关闭 |
| 409 | `ORDER_PRICE_CHANGED` | 预结算后价格发生变化 |
| 409 | `PAYMENT_ALREADY_COMPLETED` | 支付已完成 |
| 409 | `PAYMENT_AMOUNT_MISMATCH` | 支付金额不一致 |
| 409 | `PAYMENT_SCENE_UNAVAILABLE` | 当前客户端或商户权限不支持所选微信支付场景 |
| 409 | `CLIENT_CAPABILITY_STALE` | 客户端能力版本已更新，需刷新能力后重新结算 |
| 502 | `PAYMENT_PROVIDER_ERROR` | 支付渠道异常 |
| 502 | `TRACKING_PROVIDER_ERROR` | 物流轨迹渠道暂时不可用，不影响已发货状态 |

## 退款

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 409 | `REFUND_NOT_ALLOWED` | 当前订单不可退款 |
| 409 | `REFUND_QUANTITY_EXCEEDED` | 退款数量超过剩余可退数量 |
| 409 | `REFUND_AMOUNT_EXCEEDED` | 累计退款超过实际支付 |
| 409 | `REFUND_ALREADY_COMPLETED` | 退款已完成 |
| 409 | `EXCHANGE_NOT_ALLOWED` | 商品、数量、SKU 或当前状态不允许换货 |
| 409 | `EXCHANGE_QUANTITY_EXCEEDED` | 换货数量超过剩余可换数量 |
| 409 | `EXCHANGE_RETURN_DEADLINE_EXPIRED` | 换货寄回期限已过，申请已关闭 |
| 409 | `RETURN_SUBMISSION_EXPIRED` | 已超过提交退货物流期限 |
| 422 | `RETURN_LOGISTICS_REQUIRED` | 实体退货缺少物流信息 |
| 422 | `RETURN_SHIPPING_PROOF_REQUIRED` | 运费补偿缺少物流单号或付款凭证 |
| 502 | `REFUND_PROVIDER_ERROR` | 现金退款渠道异常 |

## 后台配置

| HTTP | 错误码 | 说明 |
|---:|---|---|
| 404 | `SETTING_NOT_FOUND` | 配置项不存在或不允许动态修改 |
| 409 | `SETTING_VERSION_CONFLICT` | 配置版本已变化 |
| 409 | `SETTING_APPROVAL_REQUIRED` | 高风险配置缺少有效审批 |
| 422 | `SETTING_VALUE_INVALID` | 配置值不符合类型、范围或选项要求 |
