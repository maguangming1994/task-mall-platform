# PostgreSQL 索引与并发策略

## 1. 唯一约束

- 所有对外 `public_id` 唯一。
- `user_identities(provider, provider_user_id)` 唯一。
- `legal_document_versions(document_type, version)`、`data_retention_rules(rule_key, version)` 唯一。
- `user_consents(user_id, legal_document_version_id, action, created_at)` 只追加；同类进行中的 `privacy_requests(user_id, request_type)` 唯一。
- `data_deletion_jobs(idempotency_key)` 唯一；有效 `legal_holds(object_type, object_id)` 建部分唯一索引。
- `task_submissions(task_claim_id, submission_no)` 唯一。
- `task_appeals(task_claim_id, appeal_no)` 唯一。
- `task_reward_deliveries(idempotency_key)` 唯一。
- `task_reward_budgets(task_id, reward_sequence)` 唯一。
- `task_reward_reservations(task_claim_id, reward_sequence)` 和 `task_reward_reservations(idempotency_key)` 唯一。
- `reward_budget_transactions(idempotency_key)` 唯一。
- `task_publishers(publisher_type, admin_user_id)` 在后台账号非空时唯一。
- 未来 `task_publishers(publisher_type, linked_user_id)` 和 `(publisher_type, organization_id)` 分别在关联主体非空时唯一。
- `task_publisher_applications(publisher_type, subject_id)` 仅允许一条进行中申请；`task_publisher_qualifications(publisher_id, qualification_type, valid_from)` 唯一。
- `publisher_funding_accounts(publisher_id, currency)`、`publisher_funding_transactions(idempotency_key)` 唯一。
- `task_publish_requests(public_id)` 唯一，每个任务仅允许一条进行中的发布申请。
- `task_tags(code)`、`task_tag_relations(task_id, tag_id)` 唯一。
- 每个任务与栏目仅允许一条进行中的 `task_placement_requests`。
- `task_versions(task_id, version_no)` 唯一，每个任务仅允许一条进行中的重大修改申请。
- 同一用户、任务只允许一条进行中的 `task_reports`；`allowed_external_domains(domain)` 唯一。
- `task_claims(task_id, user_id, cycle_key)` 唯一，统一约束主动领取和系统自动加入。
- `point_reward_events(event_key)` 唯一。
- `point_transactions(idempotency_key)` 唯一。
- `point_accounts(user_id)` 唯一。
- `point_debt_allocations(point_debt_id, point_transaction_id)` 唯一。
- `check_ins(user_id, check_in_date)` 唯一。
- `referrals(invited_user_id)` 唯一。
- `product_skus(sku_code)` 唯一。
- `sku_purchase_options(sku_id, purchase_mode)` 唯一。
- `inventory_reservations(order_item_id, sku_id)` 唯一。
- `shipping_templates(public_id, version)` 唯一。
- `exchanges(exchange_no)` 唯一。
- `orders(order_no)`、`payments(payment_no)`、`refunds(refund_no)` 唯一。
- `withdrawals(withdrawal_no)` 唯一，第三方转账号非空时唯一。
- `payments(provider_trade_no)` 在非空时唯一。
- `user_coupons(coupon_code)` 唯一。
- 关键库存和优惠券流水的幂等键唯一。

## 2. 高频查询索引

| 表 | 索引列 | 用途 |
|---|---|---|
| `task_placements` | `(section, pinned, pin_order, starts_at, ends_at)` | 任务大厅栏目和运营置顶 |
| `tasks` | `(status, category, task_type, claim_ends_at)` | 大厅分类、日常和限时栏目 |
| `task_tag_relations` | `(tag_id, task_id)` | 标签筛选 |
| `tasks` | `(publisher_id, status, updated_at desc, id desc)` | 前端发布中心的“我发布的任务” |
| `task_publish_requests` | `(status, submitted_at, id)` | 后台发布审批队列 |
| `task_change_requests` | `(status, created_at, id)` | 重大修改审批队列 |
| `task_status_logs` | `(task_id, created_at desc, id desc)` | 状态审计时间线 |
| `task_risk_cases` | `(status, risk_level, response_due_at, id)` | 风险审核与超时升级队列 |
| `task_reports` | `(task_id, status, created_at desc, id desc)` | 任务举报处理 |
| `task_submission_fingerprints` | `(text_hash, id)` | 重复材料候选检索 |
| `task_publisher_penalties` | `(publisher_id, status, created_at desc)` | 发布权限校验与处罚历史 |
| `task_claims` | `(user_id, status, created_at desc)` | 我的任务 |
| `task_claims` | `(status, completion_deadline_at, id)` | 扫描未完成过期并释放占位 |
| `task_submissions` | `(status, submitted_at)` | 后台审核队列 |
| `task_submissions` | `(status, review_due_at, id)` | 审核逾期扫描 |
| `task_reward_deliveries` | `(status, next_retry_at, id)` | 奖励失败重试队列 |
| `task_reward_budgets` | `(budget_pool_id, status, id)` | 预算池占用和结算 |
| `task_reward_reservations` | `(task_reward_budget_id, status, id)` | 预留、发放和释放汇总 |
| `task_appeals` | `(status, created_at, id)` | 申诉审核队列 |
| `point_lots` | `(user_id, expires_at, id)` | 最早过期优先扣除 |
| `point_transactions` | `(user_id, created_at desc, id desc)` | 积分明细 |
| `point_debts` | `(user_id, status, created_at, id)` | 最早欠额优先偿还 |
| `community_posts` | `(status, published_at desc, id desc)` | 论坛列表 |
| `community_comments` | `(post_id, status, created_at, id)` | 评论列表 |
| `products` | `(status, category_id, sort_order, id)` | 商城列表 |
| `inventory_reservations` | `(status, expires_at, id)` | 扫描并释放过期预占 |
| `inventories` | `(low_stock_threshold, sku_id)` | 结合可售库存筛选低库存 SKU |
| `shipping_template_regions` | `(template_id, region_code)` | 结算时匹配配送区域和附加费 |
| `orders` | `(user_id, status, created_at desc, id desc)` | 用户订单列表 |
| `orders` | `(status, created_at, id)` | 后台订单队列 |
| `payments` | `(order_id, status)` | 订单支付查询 |
| `refunds` | `(order_id, status, created_at desc)` | 售后查询 |
| `exchanges` | `(order_item_id, status, created_at desc)` | 校验剩余可换数量和售后查询 |
| `fulfillment_tracking_events` | `(fulfillment_id, occurred_at, id)` | 物流轨迹时间线 |
| `withdrawals` | `(user_id, created_at desc, id desc)` | 用户提现记录 |
| `withdrawals` | `(status, created_at, id)` | 财务审核和渠道处理队列 |
| `user_coupons` | `(user_id, status, valid_to)` | 可用优惠券 |
| `notifications` | `(user_id, read_at, created_at desc)` | 消息中心 |

列表翻页数据量增长后使用 `created_at + id` 游标，避免深页 `offset`。

任务标题和公开发布主体名称建立 PostgreSQL 全文检索或 trigram 索引；只索引已发布且公开可见任务。定向保密任务始终先按资格范围过滤，不能依赖搜索结果层事后隐藏。

## 3. 部分索引

建议使用 PostgreSQL 部分索引：

- 可用积分批次：`where remaining_points > 0 and status in ('available','partially_used')`。
- 未偿积分欠额：`where remaining_debt_points > 0 and status in ('outstanding','partially_repaid')`。
- 待支付订单：`where status = 'pending_payment'`，用于超时扫描。
- 有效库存预占：`where status = 'reserved'`，用于过期释放和支付确认。
- 待履约超时订单：`where status = 'pending_fulfillment' and fulfill_by is not null`。
- 待自动完成订单：`where status = 'fulfilled' and auto_complete_at is not null`。
- 待审核提交：`where status in ('submitted','reviewing')`。
- 待过期用户任务：`where slot_status = 'reserved' and status = 'claimed'`。
- 待重试任务奖励：`where status = 'failed' and next_retry_at is not null`。
- 可用优惠券：`where status = 'unused'`。
- 待处理支付回调和退款组件：按非终态建立索引。

## 4. 外键与删除策略

- 账务、订单、支付、退款、积分和库存表使用 `restrict`，禁止级联删除。
- 订单项必须保留商品快照，商品软删除不影响历史订单。
- 用户注销采用状态和脱敏，不删除交易与审计记录。
- `user_identities(provider, provider_user_id)` 唯一，避免同一外部身份绑定多个用户。
- `user_sessions(refresh_token_hash)` 唯一；按 `(user_id, revoked_at, expires_at)` 查询和批量撤销活跃会话。
- `user_devices(user_id, last_active_at desc)` 支持设备上限和安全中心列表；设备指纹只保存摘要。
- `account_cancellation_requests` 对每个用户的进行中状态建立部分唯一索引，避免重复进入冷静期。
- 社区帖子与评论可软删除，违规记录和积分撤销记录永久保留。
- 配置类历史规则使用版本化或失效时间，不直接覆盖影响历史解释的字段。

## 5. 并发控制

### 任务名额

在同一事务中依次锁定任务计数和各奖励预算，仅当 `active_reservation_count + successful_reward_count < reward_quota_total` 且全部奖励组件均可预留时创建用户任务；任一失败整体回滚。成功后历史领取数与当前占位数各加一。重复请求通过用户任务周期唯一键和奖励预留幂等键返回首次结果。

### 任务奖励预算

发布时锁定预算池和任务预算行；按稳定顺序锁定多个奖励组件，避免死锁。领取、自动加入、发放和释放时使用条件更新，保证 `reserved + issued <= locked`。任务关闭后只有进行中审核、申诉和奖励重试全部结清，才释放未使用预算。

### 库存

使用原子条件更新或行锁，确保 `reserved_quantity + quantity <= on_hand_quantity`。预占、释放和正式扣减必须和库存流水在同一事务提交。

### 积分

按 `expires_at, id` 锁定用户可用批次，逐批冻结或扣除。积分流水、批次余额和分配明细在同一事务完成。

### 用户余额和提现

锁定钱包账户后冻结或扣除余额，钱包流水与账户汇总在同一事务提交。提现请求以提现单号和幂等键去重；渠道成功回调只能将冻结余额扣除一次，失败和拒绝只能解冻一次。

### 优惠券

使用条件更新将 `unused` 改为 `locked`，只有一个订单可以成功锁定。取消、支付和返券均使用幂等键。

### 支付回调

先以回调唯一标识去重，再锁定支付单。已成功支付的记录不再重复推进订单、积分和库存。

### 部分退款

锁定订单项和退款汇总，校验“本次 + 累计已退 <= 实际支付/购买数量”。最后一次退款吸收剩余整数差额。

### 退货物流

实体退货使用条件更新提交物流，且必须在截止时间前。定时任务只关闭仍处于等待物流且 `return_submit_deadline_at` 已过期的售后；无需物流和虚拟商品不进入该状态。

## 6. 数据库检查约束

- 金额、积分、数量和汇总余额非负。
- 平台预算池满足 `locked + issued <= total`；任务预算满足 `reserved + issued <= locked`。
- 任务满足 `active_reservation_count + successful_reward_count <= reward_quota_total`，三个计数字段均非负。
- 任务预算总奖励次数大于零；周期任务的单周期次数大于零且不超过总次数。
- 积分批次 `remaining + frozen <= granted`。
- 库存 `reserved <= on_hand`。
- 订单累计退款不超过实际支付。
- 同一订单余额实付和外部渠道实付不能同时大于零。
- 购买方案满足模式：纯现金积分为零、纯积分现金为零、混合模式两者都大于零。
- 优惠券现金权益和积分权益至少一个大于零。
- 开始时间早于结束时间。

## 7. 索引验证

每个迭代结束使用真实规模测试数据执行 `EXPLAIN (ANALYZE, BUFFERS)`。不因为“可能会用到”而建立大量索引；写入频繁的流水表尤其要控制索引数量。
