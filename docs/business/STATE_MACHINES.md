# 核心业务状态机

状态只能通过明确的业务动作转换，禁止直接在后台修改数据库状态。

## 1. 任务状态

```text
draft → pending_approval → scheduled → published → paused → published
   │          └── rejected ──→ draft          ├→ expired
   └────────────────────────────→ published   └→ closed → archived
                                                expired → archived
```

- `draft`：草稿，可编辑。
- `pending_approval`：已提交发布，等待必要的内容、财务或风险审批。
- `scheduled`：已通过发布校验或审批，等待设定时间自动发布。
- `published`：已发布，可以领取。
- `paused`：暂停领取。
- `expired`：超过截止时间。
- `closed`：运营人员关闭。
- `archived`：所有业务结清后的只读归档状态，不能恢复或编辑。

首版任务仅由持有后台账号的平台管理员发布，可以使用管理后台或用户端任务发布中心。两种渠道共用本状态机；前端发布不能绕过 `pending_approval` 和高风险审批。已发布任务修改奖励、名额和截止时间时必须记录变更日志；不得影响已经领取或系统自动加入用户的任务快照。

关闭任务不能恢复，需复制成新任务重新发布。到期或关闭任务只有在审核、申诉、奖励、预算和投诉全部结清后才能进入 `archived`。

## 2. 用户任务状态

```text
claimed → submitted → reviewing → approved
   │         │            │
   │         └→ withdrawn └→ rejected → submitted
   ├→ cancelled                          └→ appealed → appeal_reviewing → approved/rejected
   └→ expired

奖励子状态：pending → processing → completed
                         └→ partially_failed → processing
```

- `claimed`：已领取，或由系统自动加入后等待用户完成行为。
- `submitted`：已提交成果。
- `withdrawn`：审核开始前撤回；重新提交仍消耗新的提交次数。
- `reviewing`：审核中。
- `approved`：审核或申诉通过，触发奖励投递。
- `rejected`：驳回，可在规则允许时重新提交。
- `appealed/appeal_reviewing`：最终驳回后进入一次申诉及复核。
- `pending/processing/partially_failed/completed/revoked`：领取记录的奖励汇总子状态；每个奖励组件另有独立投递状态。
- `cancelled`：用户或后台取消。
- `expired`：超过提交时间。

`claim_and_submit` 使用提交、审核、驳回和申诉分支；`claim_and_auto_verify` 与 `auto_enroll` 由可信服务端事件从 `claimed` 直接推进到 `approved`，不创建虚假的提交或审核记录。审核开始后原提交不可撤回或覆盖。审核期限默认 48 小时，超时只标记逾期并提醒，不自动通过。申诉默认在最终驳回后 7 天内发起一次，申诉审核人不能是原审核人。

`approved` 触发奖励时使用用户任务 ID 与奖励类型组成幂等事件键。奖励失败不回退审核通过状态，各奖励组件独立重试并可人工补偿。

## 3. 内容状态

```text
draft → pending_review → published → hidden → deleted
                   ↓          ↓
                rejected   reported
```

- 帖子审核通过后才能产生发帖积分。
- 评论可以先发布后审核，但奖励事件必须经过防刷校验。
- 内容被判违规或删除时，根据积分规则创建撤销流水。

## 4. 订单状态

```text
pending_payment → pending_fulfillment → fulfilled → completed
       ↓                  ↓                 ↓
   cancelled      partially_refunded   after_sale
                          ↓                 ↓
                       refunded ←───────────┘
```

- `pending_payment`：纯现金或混合支付等待现金支付。
- `pending_fulfillment`：已完成所需现金和积分支付，等待发货。
- `fulfilled`：已完成实体发货、无需物流确认或虚拟交付；具体方式查看履约记录。
- `completed`：确认收货或自动完成。
- `cancelled`：未完成支付前取消或超时关闭。
- `after_sale`：存在进行中的售后。
- `partially_refunded`：已完成部分退款，订单仍有保留商品。
- `refunded`：全部可退商品已退款。

纯积分订单创建并成功扣积分后直接进入 `pending_fulfillment`。

履约记录支持 `logistics/no_logistics/virtual_delivery`。同一次结算按交付类型拆成独立订单；实体物流需要承运商和单号，无需物流及虚拟交付不需要物流字段。

- 待支付和库存预占默认 15 分钟，超时关闭必须同时释放预占库存、冻结积分和锁定优惠券。
- 实体、无需物流、虚拟交付默认履约期限分别为付款后 48 小时、24 小时和 10 分钟。
- 默认自动完成时间分别为实体发货后 10 天、无需物流交付后 3 天、虚拟交付后 1 天。
- 履约超期进入异常队列，不直接自动退款；存在进行中售后时暂停自动完成。

## 5. 支付单状态

```text
pending → succeeded → partially_refunded → refunded
   ↓          ↓
 failed     refund_failed
   ↓
 closed
```

- 支付回调必须验签、核对订单号、现金金额、商户和应用身份。
- `succeeded` 只能成功写入一次，重复回调返回成功但不重复处理业务。
- 支付查询补偿可以将遗漏回调的支付单推进到 `succeeded`。

## 6. 退款单状态

```text
created → reviewing → approved → processing → succeeded
               ↓          ↓           ↓
            rejected   cancelled   partially_failed
                                      ↓
                                  processing
```

退款单下设现金退款、积分退回、优惠券返还和退货运费四类处理组件。整单退款只有所有必需组件成功后才进入 `succeeded`。

部分退款一般不需要优惠券返还组件；整单取消或整单退款需要确保优惠券只返还一次。

需要物流的退货获批后进入等待用户寄回状态，7 天内未提交物流自动关闭；无需物流和虚拟商品直接跳过等待寄回状态。

## 6.1 换货单状态

```text
created → reviewing → approved → waiting_return → return_in_transit
              ↓          ↓              ↓                 ↓
           rejected   closed          closed          received
                                                           ↓
replacement_pending → replacement_shipped → completed
```

- MVP 仅允许同一订单项、同一 SKU 换货，不计算商品差价。
- 需要寄回时，获批后 7 天未填写物流自动关闭；无需物流场景跳过寄回物流。
- 补发可以使用新的履约记录和物流单号，数量不得超过已验收的换货数量。
- 买家、卖家或物流责任决定往返运费承担；补偿默认每包裹不超过 30 元，超额人工审核。

## 7. 积分批次状态

```text
available → partially_used → exhausted
    ↓             ↓
 frozen       expired
    ↓
available / revoked
```

- `available`：可用。
- `partially_used`：部分已消费。
- `frozen`：混合支付等待现金结果。
- `exhausted`：剩余积分为零。
- `expired`：到达有效期且仍有剩余。
- `revoked`：奖励行为被撤销。

批次余额由积分流水及分配明细驱动，不允许无流水直接改动。

## 7.1 积分欠额状态

```text
outstanding → partially_repaid → repaid
     └──────────────→ waived
```

- 撤销积分不足时创建 `outstanding` 欠额，不产生负可用余额。
- 新入账积分优先偿还最早欠额，部分偿还进入 `partially_repaid`，全部偿还进入 `repaid`。
- 后台减免进入 `waived` 或部分减免后的 `partially_repaid`；单次超过 1000 积分需要超级管理员二次审核。
- 欠额大于零时禁止积分消费和冻结。

## 8. 优惠券状态

```text
unused → locked → used → returned → unused
   ↓        ↓        ↓
expired  unused   returned_expired
```

- 创建订单时锁定优惠券。
- 订单取消时释放或返还。
- 支付完成后标记已使用。
- 整单退款后返还；如果原券已过期，按返券宽限规则生成新的可用截止时间。

## 9. 库存状态动作

库存不使用单一业务状态，而使用库存流水：

- `reserve`：下单预占。
- `release`：取消或超时释放。
- `commit`：支付完成后正式扣减。
- `return`：退货入库。
- `adjust`：盘点调整，需要原因和审计。

任意时刻必须满足：可售库存、预占库存和实际库存之间的约束，不能出现负数。
