# 核心字段字典

本字典用于指导 Laravel Migration。字段仍可在实现前微调，但金额、积分、状态和流水约束不应随意改变。

## 1. 通用字段

| 字段 | PostgreSQL 类型 | 规则 |
|---|---|---|
| `id` | `bigint` | 内部主键 |
| `public_id` | `char(26)` | 对外 ULID，唯一且不可枚举 |
| `created_at` | `timestamptz` | 创建时间 |
| `updated_at` | `timestamptz` | 更新时间 |
| `deleted_at` | `timestamptz null` | 仅允许非账务业务表软删除 |
| `version` | `integer` | 需要乐观锁的汇总表使用 |

现金字段统一以 `_amount` 结尾，单位为人民币分；积分字段统一以 `_points` 结尾，使用 `bigint`。状态使用短字符串并添加检查约束，不使用难以演进的数据库枚举。

## 2. 用户与身份

### `users`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外用户 ID，唯一 |
| `nickname` | `varchar(80)` | 昵称 |
| `avatar_file_id` | `bigint null` | 头像文件 |
| `phone` | `varchar(32) null` | 加密或受控访问；标准化后唯一 |
| `phone_hash` | `char(64) null` | 标准化手机号的 HMAC 摘要，用于唯一约束与查找，不替代加密手机号 |
| `phone_verified_at` | `timestamptz null` | 手机验证时间 |
| `profile_completed_at` | `timestamptz null` | 完善资料奖励判断依据 |
| `status` | `varchar(24)` | `active/disabled/cancelled` |
| `cancelled_at` | `timestamptz null` | 注销时间 |

### `user_identities`

| 字段 | 类型 | 规则 |
|---|---|---|
| `user_id` | `bigint` | 用户外键 |
| `provider` | `varchar(32)` | `phone/wechat_mini/wechat_oa` |
| `provider_user_id` | `varchar(191)` | OpenID 或标准化手机号摘要 |
| `union_id` | `varchar(191) null` | 微信 UnionID |
| `metadata` | `jsonb` | 非敏感扩展信息 |
| `last_login_at` | `timestamptz null` | 最近登录 |

唯一约束：`provider + provider_user_id`。

### `user_devices`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外设备记录 ID |
| `user_id` | `bigint` | 用户外键 |
| `device_fingerprint_hash` | `varchar(191)` | 设备标识摘要，不保存可逆指纹 |
| `client_platform` | `varchar(32)` | `wechat_mini/wechat_h5/web_h5` |
| `device_name` | `varchar(120) null` | 用户可识别的设备名称 |
| `last_active_at` | `timestamptz` | 最近活跃时间 |
| `revoked_at` | `timestamptz null` | 移除或安全退出时间 |

### `user_sessions`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外会话 ID |
| `user_id` | `bigint` | 用户外键 |
| `user_device_id` | `bigint` | 关联设备 |
| `refresh_token_hash` | `varchar(191)` | 只保存不可逆摘要，唯一 |
| `issued_at` | `timestamptz` | 签发时间 |
| `expires_at` | `timestamptz` | 默认签发后 30 天 |
| `revoked_at` | `timestamptz null` | 撤销时间 |
| `revoke_reason` | `varchar(64) null` | 退出、轮换、禁用、注销或风控 |

### `user_addresses`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外地址 ID |
| `user_id` | `bigint` | 地址所有者，服务端强制隔离 |
| `recipient_name` | `text` | 应用层加密 |
| `phone` | `text` | 应用层加密 |
| `province/city/district` | `varchar(80)` | 配送区域匹配字段 |
| `detail` | `text` | 应用层加密的详细门牌 |
| `is_default` | `boolean` | 每个用户始终至多一条默认地址 |
| `idempotency_key_hash` | `char(64)` | 用户维度创建幂等摘要 |
| `deleted_at` | `timestamptz null` | 用户删除后软删除，注销时物理清理 |

### `user_asset_accounts`

| 字段 | 类型 | 规则 |
|---|---|---|
| `user_id` | `bigint` | 每个用户唯一账户 |
| `points_available/frozen/debt` | `bigint` | 非负积分汇总 |
| `wallet_available_cents/frozen_cents` | `bigint` | 人民币分；可用或冻结余额非零均影响注销资格 |
| `coupon_available` | `integer` | 当前可用券数量汇总 |
| `version` | `bigint` | 账本并发版本 |

### `user_milestones`

用户与 `milestone_code` 唯一；记录达成时间及 `pending/delivered/failed` 奖励状态，防止资料完善等奖励重复发放。

### `account_cancellation_requests`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外注销申请 ID |
| `user_id` | `bigint` | 同一用户仅允许一条进行中申请 |
| `status` | `varchar(24)` | `cooling_off/cancelled/executed/rejected` |
| `eligibility_snapshot` | `jsonb` | 申请时未结业务和资产检查结果 |
| `requested_at` | `timestamptz` | 申请时间 |
| `cooling_off_ends_at` | `timestamptz` | 默认申请后 7 天 |
| `cancelled_at` | `timestamptz null` | 用户撤销或登录撤销时间 |
| `executed_at` | `timestamptz null` | 注销实际执行时间 |

### `legal_document_versions`、`user_consents`

协议版本保存文档类型、版本号、适用端、内容摘要、生效/失效时间和状态；用户同意保存用户、文档版本、动作 `accept/withdraw`、客户端、IP 摘要、请求 ID 和时间。`document_type + version` 唯一，同意记录不得更新覆盖。

### `privacy_requests`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外请求 ID |
| `user_id` | `bigint` | 申请用户 |
| `request_type` | `varchar(24)` | `access/copy/correction/deletion/withdraw_consent` |
| `scope` | `jsonb` | 请求涉及的数据类别，服务端白名单校验 |
| `status` | `varchar(24)` | `submitted/verifying/processing/completed/partially_completed/rejected/expired` |
| `blockers` | `jsonb` | 未结业务、争议、法定期限或法律冻结 |
| `result_file_id` | `bigint null` | 数据副本，私有且短期有效 |
| `completed_at` | `timestamptz null` | 完成时间 |

### `data_retention_rules`、`data_deletion_jobs`、`legal_holds`

规则保存稳定键、起算事件、期限天数、动作、版本和状态；删除任务保存规则版本、游标、扫描数、删除数、失败数、幂等键和重试时间；冻结保存对象类型/ID、原因、批准人、开始/结束和解除记录。业务表按需要增加 `retention_rule_key`、`retention_until`，不得用永久空值规避到期处理。

### `admin_users`、`roles`、`permissions`、`admin_user_role`、`permission_role`

后台账号与普通用户身份隔离；后台 RBAC 保存角色、权限和账号绑定关系。账号禁用后其后台会话与任务发布会话必须立即失效。

### `approval_requests`、`approval_actions`

高风险动作、业务对象、申请人、阈值、申请原因、状态、审核人、审核意见和执行结果。申请人与二次审核人不能相同。

### `system_settings`

配置键、分类、数据类型、控件类型、当前已发布值、说明、是否高风险和当前版本。配置值使用 JSONB，但服务端根据数据类型执行严格校验。客户端能力配置发布时生成单调递增的能力版本，供结算和支付校验。

### `system_setting_versions`

配置键、版本、草稿值、状态、修改人、审核人、修改原因、发布时间和生效时间。已发布版本不可修改，回滚生成新版本。

## 3. 任务

### `reward_budget_pools`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外预算池 ID |
| `asset_type` | `varchar(24)` | `points/coupon/wallet_cash` |
| `coupon_template_id` | `bigint null` | 优惠券预算必填 |
| `total_amount` | `bigint` | 积分数、券数量或人民币分，非负 |
| `locked_amount` | `bigint` | 已被任务锁定，非负 |
| `issued_amount` | `bigint` | 已实际发行，非负 |
| `status` | `varchar(16)` | `active/frozen/closed` |
| `version` | `integer` | 并发控制 |

`available_amount = total_amount - locked_amount - issued_amount` 由服务端计算；余额现金预算不得与用户钱包余额表混用。

### `reward_budget_transactions`

预算池、任务预算、用户奖励预留、类型 `fund/lock/unlock/reserve/issue/release/recover/adjust`、变动值、变动前后值、幂等键、业务对象、操作人、审批记录、请求 ID 和时间。流水只追加不可修改删除。

### `task_publishers`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外发布主体 ID |
| `publisher_type` | `varchar(24)` | 首版只允许 `platform_admin`；预留 `user/organization` |
| `admin_user_id` | `bigint null` | 首版必填，关联后台账号 |
| `linked_user_id` | `bigint null` | 用户端展示与操作时关联的普通用户身份 |
| `organization_id` | `bigint null` | 未来机构主体；首版必须为空 |
| `verification_status` | `varchar(24)` | `unverified/pending/verified/expired/rejected`；平台管理员视为已验证 |
| `publisher_level` | `varchar(24)` | 未来额度等级；首版为 `platform` |
| `status` | `varchar(16)` | `active/suspended/disabled` |
| `created_at` | `timestamptz` | 创建时间 |

首版唯一约束 `publisher_type + admin_user_id`。只有后台账号启用、拥有平台管理员角色及 `task.frontend_publish` 权限时，主体才能签发前端发布会话。

第 15 批已实现平台管理员发布主体与独立短时发布会话；会话只保存令牌摘要，并在每次请求实时复查后台账号、平台管理员角色和前端发布权限。

### `task_publisher_applications`、`task_publisher_qualifications`、`task_publisher_limits`

申请表保存主体类型、申请阶段、协议版本、风险结论和审核状态；资质表保存资质类型、脱敏编号、材料文件引用、有效期和复审状态；额度表保存允许类别、在线任务数、人数、单笔奖励及总预算上限。三个表首发不得产生 `user/organization` 有效记录。

### `publisher_funding_accounts`、`publisher_funding_transactions`

资金账户按发布主体和币种唯一，分别保存可用、锁定、争议冻结及待退金额；资金流水保存 `fund/lock/unlock/issue/refund/freeze/unfreeze/adjust`、变动前后值、原资金路径、幂等键、审批和对账状态。禁止与 `wallet_accounts` 共用余额字段或流水。

### `task_publish_requests`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外申请 ID |
| `task_id` | `bigint` | 任务 |
| `publisher_id` | `bigint` | 发布主体 |
| `request_type` | `varchar(24)` | `publish/major_update` |
| `source_channel` | `varchar(24)` | `admin_web/user_frontend` |
| `status` | `varchar(24)` | `pending/approved/rejected/cancelled` |
| `validation_snapshot` | `jsonb` | 提交时完整校验及风险结果 |
| `submitted_by_admin_id` | `bigint` | 后台身份操作人 |
| `reviewed_by_admin_id` | `bigint null` | 不得等于提交人 |
| `review_reason` | `text null` | 驳回必填 |
| `submitted_at` | `timestamptz` | 提交时间 |
| `reviewed_at` | `timestamptz null` | 处理时间 |

### `task_publish_approvals`（第 11 批已实现）

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外复核 ID |
| `task_id` | `bigint` | 任务 |
| `task_version_id` | `bigint unique` | 精确绑定不可变任务版本 |
| `requested_by_admin_id` | `bigint` | 发布申请人，不能成为复核人 |
| `status` | `varchar(24)` | `pending/approved/rejected/withdrawn` |
| `triggered_rules` | `jsonb` | 单次余额或总余额预算门槛命中代码 |
| `threshold_snapshot` | `jsonb` | 申请时门槛及计算总预算快照 |
| `reviewed_by_admin_id` | `bigint null` | 具有财务权限的另一位管理员 |
| `review_note` | `varchar(500) null` | 复核意见 |
| `review_idempotency_key_hash` | `char(64) null unique` | 复核防重放摘要 |
| `reviewed_at` | `timestamptz null` | 复核时间 |

批准本身不锁预算；发布者再次执行幂等发布动作时，才按预算池主键稳定顺序锁行并一次性锁定所有奖励组件。

### `task_tags`、`task_tag_relations`

标签代码、展示名称、状态、排序，以及任务与标签关联。`task_tags(code)` 和 `task_tag_relations(task_id, tag_id)` 唯一。

### `task_placement_requests`

任务、申请栏目、推荐理由、申请人、状态 `pending/approved/rejected/cancelled`、审批人、审批理由和时间。一个任务同一栏目只允许一条进行中申请。

### `task_placements`

任务、栏目 `recommended/limited_time/daily/growth`、是否置顶、置顶顺序、展示开始/结束时间、批准人和版本。同一任务同一栏目有效期不得重叠；首页默认最多3个有效置顶由服务层和配置共同限制。

### `tasks`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外任务 ID |
| `publisher_id` | `bigint` | 发布主体，首版必须为平台管理员 |
| `creation_channel` | `varchar(24)` | `admin_web/user_frontend` |
| `visibility` | `varchar(24)` | `public/targeted_private` |
| `title` | `varchar(160)` | 标题 |
| `category` | `varchar(24)` | `onboarding/daily/community/commerce/research/campaign` |
| `task_type` | `varchar(24)` | `one_time/daily/weekly/repeatable` |
| `participation_mode` | `varchar(32)` | `claim_and_submit/claim_and_auto_verify/auto_enroll` |
| `description` | `text` | 任务说明 |
| `reward_quota_total` | `integer` | 最大成功奖励次数，必须大于零 |
| `reward_quota_per_cycle` | `integer null` | 日/周/重复任务必填 |
| `historical_claim_count` | `integer` | 历史成功领取数，只增不减 |
| `active_reservation_count` | `integer` | 当前仍占位的用户任务数 |
| `successful_reward_count` | `integer` | 已正式消耗奖励名额数 |
| `completion_window_hours` | `integer` | 领取后完成期限，默认24小时 |
| `submission_limit` | `integer` | 允许提交次数 |
| `review_deadline_hours` | `integer` | 审核期限，任务可覆盖全局默认值 |
| `appeal_deadline_days` | `integer` | 最终驳回后的申诉期限，默认 7 天 |
| `appeal_limit` | `integer` | 默认 1 次 |
| `verification_mode` | `varchar(24)` | `manual/automatic`；与参与方式保持一致 |
| `claim_starts_at` | `timestamptz` | 开始领取 |
| `claim_ends_at` | `timestamptz` | 结束领取 |
| `submit_ends_at` | `timestamptz` | 最晚提交 |
| `status` | `varchar(24)` | `draft/pending_approval/scheduled/published/paused/expired/closed/archived` |
| `rules` | `jsonb` | 附件、字段、资格等可扩展规则 |
| `version` | `integer` | 并发编辑版本 |
| `current_published_version_id` | `bigint null` | 当前公开不可变版本 |
| `archived_at` | `timestamptz null` | 全部业务结清后的归档时间 |
| `updated_at` | `timestamptz` | 最近修改时间，用于发布中心排序 |

### `task_versions`

任务、连续版本号、完整规则/奖励/材料/资格/时间快照、变更类型 `minor/major/rollback`、来源版本、状态 `draft/pending_approval/published/superseded/rejected`、创建人、发布人和时间。唯一约束 `task_id + version_no`；已发布版本不可修改。

### `task_change_requests`

任务、目标版本、服务端生成的字段差异、判定的普通/重大类型、预算/风险结果、状态、申请人、审批人、理由和时间。申请人不能审批自己的重大修改。

### `task_status_logs`

任务、原状态、新状态、动作 `pause/resume/close/emergency_close/archive`、已有用户策略、原因、操作渠道、操作人、审批记录和时间。

第 11 批实际使用 `task_status_changes`，动作已覆盖 `request_publish/publish/pause/resume/close/archive`，`idempotency_key_hash` 全局唯一；发布、关闭和预算流水在同一事务提交。

### `task_risk_assessments`

业务对象类型/ID、检查阶段 `publish/change/claim/submission/reward`、规则版本、命中代码、风险等级 `low/medium/high`、证据摘要、自动建议、是否需人工和时间。原材料不得复制进普通风险字段。

### `task_risk_cases`

任务、可选用户任务/举报、风险等级、来源、状态 `open/reviewing/resolved/appealed/closed`、响应截止时间、奖励冻结状态、处理人、结论和时间。高危默认2小时、普通默认24小时。

### `task_reports`

任务、举报用户、原因 `fraud/illegal/false_task/privacy/dangerous/diversion/other`、说明、证据文件、状态和风险案件。对同一用户、任务及进行中状态建立唯一约束。

### `task_risk_actions`

风险案件、动作 `hide/stop_claiming/freeze_reward/reject_submission/restrict_user/revoke_reward/restore/compensate`、原因、操作人、审批记录和时间，只追加不可覆盖。

### `task_publisher_penalties`

发布主体、风险案件、处罚 `warning/suspend_frontend_publish/revoke_publish_permission/disable_role/disable_account`、开始/结束时间、状态、操作人和申诉结果。

### `task_submission_fingerprints`

用户任务、提交、文件摘要、规范化文本摘要、图片相似度特征版本和创建时间。特征只用于授权风控查询。

### `allowed_external_domains`

标准化域名、允许场景、风险等级、状态、申请人、审批人、有效期和原因。任务只能引用当前有效白名单域名。

### `task_rewards`

任务奖励类型 `points/coupon/wallet_balance`、积分数量、优惠券模板或余额金额。一个任务可以配置多种奖励；余额金额使用人民币分。

### `task_reward_budgets`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外任务预算 ID |
| `task_id` | `bigint` | 任务 |
| `reward_sequence` | `smallint` | 对应第几个任务奖励组件 |
| `budget_pool_id` | `bigint` | 平台预算池 |
| `max_deliveries_total` | `integer` | 活动总奖励次数，必须大于零 |
| `max_deliveries_per_cycle` | `integer null` | 日/周/重复任务必填 |
| `locked_amount` | `bigint` | 发布时锁定总值 |
| `reserved_amount` | `bigint` | 已分配给用户但未发放 |
| `issued_amount` | `bigint` | 已发放 |
| `released_amount` | `bigint` | 已释放 |
| `recovered_amount` | `bigint` | 已实际追回 |
| `status` | `varchar(24)` | `draft/locked/exhausted/settling/closed` |
| `version` | `integer` | 并发控制 |

唯一约束 `task_id + reward_sequence`。各奖励组件的 `max_deliveries_total/per_cycle` 必须与任务的总/周期奖励次数一致；锁定值由服务端计算，客户端不得提交最终金额。

### `task_reward_reservations`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外预留 ID |
| `task_reward_budget_id` | `bigint` | 任务奖励预算 |
| `task_claim_id` | `bigint` | 用户任务 |
| `reward_sequence` | `smallint` | 奖励组件序号 |
| `amount` | `bigint` | 积分、券数量或人民币分 |
| `status` | `varchar(24)` | `reserved/issued/released/recovered` |
| `idempotency_key` | `varchar(191)` | 唯一 |
| `reserved_at` | `timestamptz` | 预留时间 |
| `settled_at` | `timestamptz null` | 发放或释放时间 |

同一领取记录的全部奖励预留必须在一个事务内成功；任一失败时整体回滚用户任务和领取名额。

第 13 批实现增加 `public_id`、资产类型、唯一幂等摘要、预留/结算时间和状态；唯一约束为 `task_claim_id + reward_sequence`。

### `task_claims`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外领取 ID |
| `task_id` | `bigint` | 任务 |
| `user_id` | `bigint` | 用户 |
| `enrollment_source` | `varchar(16)` | `user/system` |
| `cycle_key` | `varchar(64)` | 本次日/周/重复周期的稳定标识 |
| `task_snapshot` | `jsonb` | 领取时任务和奖励快照 |
| `status` | `varchar(24)` | 用户任务状态 |
| `slot_status` | `varchar(16)` | `reserved/consumed/released` |
| `progress_started_at` | `timestamptz null` | 自动核验任务首次有效进度时间 |
| `completion_deadline_at` | `timestamptz` | 领取窗口与任务全局截止时间的较早值 |
| `cancel_count` | `smallint` | 当前周期取消次数，首版最多1次且不能重领 |
| `submission_count` | `integer` | 已产生提交序号数量，撤回不返还次数 |
| `appeal_count` | `integer` | 已发起申诉次数 |
| `reward_status` | `varchar(24)` | `pending/delivered/partially_delivered/delivery_failed/revoked` |
| `approved_at` | `timestamptz null` | 审核通过时间 |
| `cancelled_at` | `timestamptz null` | 取消时间 |

是否允许重复参与由任务周期决定；唯一约束使用 `task_id + user_id + cycle_key`，同一周期只能产生一条用户任务记录。

### `task_claim_status_changes`

用户任务、原/新状态、动作 `claim/auto_enroll/cancel/expire`、原因、参与者类型/ID、唯一幂等摘要和时间，只追加不覆盖。提交、审核与申诉批次继续沿用该流水扩展动作。

### `task_submissions`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外提交 ID |
| `task_claim_id` | `bigint` | 用户任务 |
| `submission_no` | `integer` | 第几次提交 |
| `content` | `jsonb` | 表单内容和附件引用 |
| `status` | `varchar(24)` | `submitted/withdrawn/reviewing/approved/rejected` |
| `submitted_at` | `timestamptz` | 提交时间 |
| `withdrawn_at` | `timestamptz null` | 审核开始前撤回时间 |
| `review_started_at` | `timestamptz null` | 审核员锁定开始时间 |
| `review_due_at` | `timestamptz` | 默认提交后 48 小时 |
| `review_reminded_at` | `timestamptz null` | 首次生成逾期提醒标记时间，不自动通过 |

唯一约束：`task_claim_id + submission_no`。

### `task_reviews`

记录提交、领取记录、审核人、结果、原因代码、说明、材料快照和审核时间。每次审核追加记录，不覆盖历史；驳回原因与说明必填。

### `task_appeals`

领取记录、原提交、申诉序号、申诉理由、补充证据、原审核人、申诉审核人、状态和处理原因。默认 `task_claim_id + appeal_no` 唯一且最多 1 次，数据库或服务约束申诉审核人与原审核人不同。

第 15 批已实现最终驳回判定、申诉期限、证据归属/扫描校验、异人审核和审核幂等；申诉最终驳回与释放名额/奖励预留在受控事务链路完成。

### `task_reward_deliveries`

领取记录、奖励预留、奖励类型、奖励快照、状态 `pending/delivered/failed`、尝试次数、下次重试时间、成功时间和失败原因。奖励预留一对一唯一；`task_reward_delivery_attempts` 记录系统/人工重试来源、管理员、原因和幂等摘要。

### `task_verification_events`

任务、用户、领取记录、可信事件类型与来源、唯一事件摘要、最小业务载荷、是否达到目标、处理状态和时间。前端请求不得直接写入该表。

## 4. 积分

### `point_accounts`

| 字段 | 类型 | 规则 |
|---|---|---|
| `user_id` | `bigint` | 用户，唯一 |
| `available_points` | `bigint` | 可用积分，非负 |
| `frozen_points` | `bigint` | 冻结积分，非负 |
| `debt_points` | `bigint` | 待偿还欠额，非负 |
| `version` | `integer` | 并发控制版本 |

### `point_rules`

| 字段 | 类型 | 规则 |
|---|---|---|
| `source_code` | `varchar(64)` | 来源代码 |
| `name` | `varchar(100)` | 后台名称 |
| `reward_points` | `bigint null` | 空表示业务对象单独配置 |
| `validity_days` | `integer null` | 空使用全局默认值 |
| `daily_limit` | `integer null` | 每日奖励次数 |
| `monthly_limit` | `integer null` | 每月奖励次数 |
| `lifetime_limit` | `integer null` | 总次数 |
| `requires_review` | `boolean` | 是否审核后到账 |
| `revoke_on_source_delete` | `boolean` | 来源删除是否追回 |
| `starts_at` | `timestamptz null` | 生效时间 |
| `ends_at` | `timestamptz null` | 失效时间 |
| `enabled` | `boolean` | 是否启用 |
| `rule_version` | `integer` | 规则版本 |

### `point_reward_events`

| 字段 | 类型 | 规则 |
|---|---|---|
| `event_key` | `varchar(191)` | 全局唯一幂等键 |
| `user_id` | `bigint` | 获得积分用户 |
| `source_code` | `varchar(64)` | 来源 |
| `source_type` | `varchar(64)` | 业务对象类型 |
| `source_id` | `varchar(64)` | 业务对象 ID |
| `rule_version` | `integer` | 使用的规则版本 |
| `status` | `varchar(24)` | `pending/processed/rejected/revoked` |
| `processed_at` | `timestamptz null` | 处理时间 |

### `point_lots`

| 字段 | 类型 | 规则 |
|---|---|---|
| `user_id` | `bigint` | 用户 |
| `source_code` | `varchar(64)` | 原始来源 |
| `source_id` | `varchar(64) null` | 来源对象 |
| `granted_points` | `bigint` | 发放数量，大于零 |
| `remaining_points` | `bigint` | 剩余可用，非负 |
| `frozen_points` | `bigint` | 已冻结，非负 |
| `expires_at` | `timestamptz` | 过期时间 |
| `status` | `varchar(24)` | `available/partially_used/frozen/exhausted/expired/revoked` |
| `rule_version` | `integer` | 发放规则版本 |

约束：`remaining_points + frozen_points <= granted_points`。

第 12 批实际表名为 `point_batches`，字段对应 `issued_points/remaining_points/frozen_points`，并增加 `public_id`、`issued_at` 和唯一 `event_key_hash`；分配表实际名为 `point_batch_allocations`。

### `point_transactions`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外流水 ID |
| `user_id` | `bigint` | 用户 |
| `type` | `varchar(32)` | `grant/freeze/unfreeze/spend/refund/expire/revoke/adjust/debt_create/debt_repay/debt_waive` |
| `change_points` | `bigint` | 有符号变化量，不为零 |
| `balance_after` | `bigint` | 交易后可用余额快照 |
| `business_type` | `varchar(64)` | 业务类型 |
| `business_id` | `varchar(64)` | 业务 ID |
| `idempotency_key` | `varchar(191)` | 同类交易唯一 |
| `remark` | `varchar(500) null` | 展示或审计备注 |

### `point_allocations`

记录某笔消费、冻结、退款对应的积分批次和数量。唯一约束建议为 `transaction_id + point_lot_id`。

### `point_debts`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外欠额 ID |
| `user_id` | `bigint` | 用户 |
| `source_reward_event_id` | `bigint` | 被撤销的原奖励事件 |
| `original_debt_points` | `bigint` | 初始欠额，大于 0 |
| `remaining_debt_points` | `bigint` | 剩余欠额，非负 |
| `status` | `varchar(24)` | `outstanding/partially_repaid/repaid/waived` |
| `reason` | `varchar(500)` | 违规、作弊或来源无效原因 |
| `settled_at` | `timestamptz null` | 偿清或全部减免时间 |

### `point_debt_allocations`

欠额、偿还/减免积分流水、来源奖励事件和分配积分。唯一约束：`point_debt_id + point_transaction_id`。新积分先按欠额创建时间偿还，来源关系不得丢失。

### `wallet_accounts`

| 字段 | 类型 | 规则 |
|---|---|---|
| `user_id` | `bigint` | 用户，唯一 |
| `currency` | `char(3)` | 默认 `CNY` |
| `available_amount` | `bigint` | 可用余额，人民币分，非负 |
| `frozen_amount` | `bigint` | 冻结余额，人民币分，非负 |
| `version` | `integer` | 并发版本 |

第 12 批复用 `user_asset_accounts.wallet_available_cents/wallet_frozen_cents/version` 保存汇总；`wallet_transactions` 同时保存可用与冻结变化及变化后双余额，避免冻结流水丢失。

### `wallet_transactions`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外流水 ID |
| `user_id` | `bigint` | 用户 |
| `type` | `varchar(32)` | `grant/freeze/unfreeze/spend/refund/withdraw/adjust/clear` |
| `change_amount` | `bigint` | 有符号人民币分，不为零 |
| `balance_after` | `bigint` | 交易后可用余额 |
| `business_type` | `varchar(64)` | 任务、订单、退款等 |
| `business_id` | `varchar(64)` | 业务对象 |
| `idempotency_key` | `varchar(191)` | 唯一 |
| `remark` | `varchar(500) null` | 原因或展示备注 |

### `wallet_allocations`

钱包交易与任务奖励、订单、退款等来源的分配关系，用于退款溯源和对账。

### `withdrawals`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外提现 ID |
| `withdrawal_no` | `varchar(32)` | 提现单号，唯一 |
| `user_id` | `bigint` | 用户 |
| `requested_amount` | `bigint` | 申请金额，人民币分 |
| `fee_amount` | `bigint` | 手续费，人民币分 |
| `fee_rate` | `integer` | 手续费率，百万分比整数；千分之六保存为 6000 |
| `received_amount` | `bigint` | 实际到账金额 |
| `channel` | `varchar(24)` | 提现渠道 |
| `recipient_identity_id` | `bigint` | 收款身份 |
| `status` | `varchar(40)` | 提现状态 |
| `provider_transfer_no` | `varchar(128) null` | 第三方转账号 |
| `failure_code` | `varchar(64) null` | 失败代码 |
| `failure_message` | `varchar(500) null` | 脱敏失败信息 |
| `reviewed_by` | `bigint null` | 审核人 |
| `reviewed_at` | `timestamptz null` | 审核时间 |

第 12 批实际表名为 `wallet_withdrawals`，补充申请/审核幂等摘要、审核意见、完成时间和渠道引用。批准只进入 `processing`，不提前扣除冻结资金；拒绝在同一事务生成解冻流水。
| `completed_at` | `timestamptz null` | 完成时间 |

提现申请先冻结钱包余额，成功后转为正式扣除，失败、拒绝或提交渠道前取消时解冻。

## 5. 社区与成长

### `check_ins`

`user_id`、`check_in_date`、`streak_days`、`reward_event_id`。唯一约束：`user_id + check_in_date`。

### `referrals`

邀请人、新用户、邀请码、绑定时间、首次有效任务及完成时间、达标订单、达标状态、达标时间、奖励事件和撤销状态。新用户只能归属一个邀请人；首次有效任务必须在关系绑定后通过或自动达成且未撤销，达标订单必须实际支付现金大于 0 且状态为已完成。

第 15 批已实现单一归属、首次任务事件、首笔现金订单事件、配置化双里程碑、月度奖励人数上限和推荐积分幂等入账；前端进度不暴露任务材料或订单内容。

### `community_posts`

作者、标题、正文、状态、审核人、审核原因、发布时间、删除时间。正文需经过安全过滤。

### `community_post_versions`、`community_attachments`

帖子版本、标题、正文、审核状态、规则版本，以及图片/视频附件、排序、文件类型和安全检查状态。

### `community_comments`

帖子、作者、父评论、正文、状态、审核信息、删除时间。限制嵌套层级。

### `content_reports`、`moderation_actions`、`content_appeals`、`user_restrictions`

举报原因和证据、审核处理、用户申诉、限制发帖/评论/登录的范围、开始和结束时间。所有处理记录只追加并保留审计。

## 6. 商品与库存

### `products`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外商品 ID |
| `product_category_id` | `bigint null` | 分类 |
| `name` | `varchar(180)` | 商品名 |
| `subtitle` | `varchar(255) null` | 副标题 |
| `description` | `text` | 商品详情 |
| `status` | `varchar(24)` | `draft/on_sale/off_shelf` |
| `current_version_no` | `integer` | 当前不可覆盖定义版本号 |
| `lock_version` | `bigint` | 乐观锁 |

### `product_skus`

商品、SKU 编码、名称、规格 JSON、图片、状态和 `fulfillment_type` 已实现。第 17 批追加运费模板、计费重量、包邮、低库存覆盖值和单笔/每日/活动期/终身限购字段；实体物流上架前必须绑定有效模板，按重量计费必须填写克重。

### `sku_purchase_options`

| 字段 | 类型 | 规则 |
|---|---|---|
| `product_sku_id` | `bigint` | SKU |
| `purchase_mode` | `varchar(24)` | `points/cash/cash_points` |
| `cash_amount` | `bigint` | 非负；现金或混合模式大于零 |
| `points` | `bigint` | 非负；积分或混合模式大于零 |
| `enabled` | `boolean` | 是否开放 |
| `starts_at` | `timestamptz null` | 可售开始 |
| `ends_at` | `timestamptz null` | 可售结束 |

唯一约束：`sku_id + purchase_mode`。价格不按首发渠道区分。

### `product_versions`、`product_status_changes`

商品定义版本保存 SPU、SKU 与购买方案完整快照，只追加不覆盖；状态变更保存上下架前后状态、原因、管理员与幂等键。商品下架不删除版本，也不改变后续订单保存的商品快照。

### `inventories`

`sku_id`、`on_hand_quantity`、`reserved_quantity`、`low_stock_threshold`、`version`。约束：数量非负，`reserved_quantity <= on_hand_quantity`，可售量实时计算；低库存阈值默认 10 并允许 SKU 覆盖。

### `inventory_reservations`

第 17 批已实现 `inventories` 与不可变 `inventory_transactions`；购物车不会创建预占，`inventory_reservations` 在第 18 批订单创建时使用。

| 字段 | 类型 | 规则 |
|---|---|---|
| `order_item_id` | `bigint` | 订单项，同一订单项与 SKU 唯一 |
| `sku_id` | `bigint` | 预占 SKU |
| `quantity` | `integer` | 大于 0 |
| `status` | `varchar(24)` | `reserved/consumed/released/expired` |
| `expires_at` | `timestamptz` | 默认创建后 15 分钟 |
| `consumed_at` | `timestamptz null` | 支付成功转正式扣减时间 |
| `released_at` | `timestamptz null` | 订单关闭或失败释放时间 |

### `inventory_transactions`

库存动作、数量、业务类型、业务 ID、交易后库存快照和幂等键。库存动作只追加。

### `shipping_templates`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外模板 ID |
| `name` | `varchar(120)` | 模板名称 |
| `charge_type` | `varchar(24)` | `fixed/by_quantity/by_weight` |
| `fixed_amount` | `bigint null` | 固定运费，人民币分 |
| `first_unit` | `numeric null` | 首件或首重，重量使用定点数 |
| `first_amount` | `bigint null` | 首件/首重费用 |
| `additional_unit` | `numeric null` | 续件或续重单位 |
| `additional_amount` | `bigint null` | 续件/续重费用 |
| `version` | `integer` | 发布版本，订单保存快照 |
| `enabled` | `boolean` | 是否允许新订单使用 |

### `shipping_template_regions`、`shipping_free_rules`

模板版本对应的省市区可配送/不可配送、偏远附加费，以及 `product/sku/cash_threshold/quantity_threshold` 包邮条件。金额使用人民币分；默认满额口径为优惠前商品现金金额。

## 7. 优惠券

### `coupon_templates`

| 字段 | 类型 | 规则 |
|---|---|---|
| `name` | `varchar(120)` | 名称 |
| `cash_discount_amount` | `bigint` | 现金优惠，非负 |
| `points_discount` | `bigint` | 积分优惠，非负 |
| `minimum_cash_amount` | `bigint` | 现金门槛 |
| `minimum_points` | `bigint` | 积分门槛 |
| `scope_type` | `varchar(24)` | `all/category/product/sku` |
| `distribution_type` | `varchar(24)` | `system/manual/campaign` |
| `per_user_limit` | `integer null` | 每人领取上限 |
| `validity_type` | `varchar(24)` | `fixed/after_receive` |
| `valid_from`、`valid_to` | `timestamptz null` | 固定有效期 |
| `validity_days` | `integer null` | 领取后有效天数，默认 30 |
| `return_grace_days` | `integer` | 返券宽限天数，默认 7 |
| `enabled` | `boolean` | 是否启用 |

现金与积分权益至少一个大于零。

### `coupon_scopes`

优惠券模板与分类、商品或 SKU 的适用范围。`scope_type = all` 时不创建范围明细。

### `user_coupons`

用户、模板、券码、有效期、状态、锁定订单、使用订单和返还来源。券码唯一；同一订单最多使用一张券。

第 12 批已实现字段包含不可变 `template_snapshot`、唯一发行事件摘要、`valid_from/valid_to` 和状态 `unused/locked/used/returned/expired/revoked`；所有状态变化追加 `coupon_transactions`。

### `asset_adjustment_requests`

用户、资产类型 `points/wallet/coupon`、操作 `increase/decrease/clear/issue`、数量、券模板、状态、二审标记、原因、申请/复核管理员、两组幂等摘要和应用时间。清零不直接覆盖汇总，而是按当时全部可用值生成反向流水。

### `coupon_allocations`

订单项、分摊现金优惠和分摊积分优惠。分摊合计必须等于订单优惠总额。

## 8. 订单

### `checkout_previews`

用户、令牌 SHA-256、规范化请求摘要与快照、服务端计价快照、5 分钟过期时间和单次消费时间。预览只保存可校验快照，不创建资产或库存预占；创建订单时必须锁定并重新计价。

### `order_batches`

结算批次号、用户、客户端、能力版本、各项现金/积分合计、预览记录、状态、创建时间和幂等键摘要。一个批次可以包含多个不同交付类型的订单。

### `orders`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外订单 ID |
| `order_no` | `varchar(32)` | 业务订单号，唯一 |
| `order_batch_id` | `bigint` | 所属结算批次 |
| `user_id` | `bigint` | 下单用户 |
| `client_platform` | `varchar(24)` | `wechat-mini/h5-wechat/h5-browser` |
| `fulfillment_type` | `varchar(24)` | `logistics/no_logistics/virtual_delivery`，单个订单唯一 |
| `status` | `varchar(32)` | 订单状态 |
| `items_cash_amount` | `bigint` | 商品现金原价合计 |
| `items_points` | `bigint` | 商品积分原价合计 |
| `cash_discount_amount` | `bigint` | 优惠券现金优惠 |
| `points_discount` | `bigint` | 优惠券积分优惠 |
| `shipping_amount` | `bigint` | 现金运费 |
| `payable_cash_amount` | `bigint` | 最终现金应付 |
| `payable_points` | `bigint` | 最终积分应付 |
| `paid_cash_amount` | `bigint` | 已支付现金 |
| `paid_wallet_amount` | `bigint` | 余额支付金额 |
| `paid_external_amount` | `bigint` | 微信等外部渠道支付金额 |
| `cash_payment_method` | `varchar(24)` | `wallet/wechat/none`，现金来源唯一 |
| `paid_points` | `bigint` | 已扣积分 |
| `refunded_cash_amount` | `bigint` | 累计已退现金 |
| `refunded_points` | `bigint` | 累计已退积分 |
| `address_snapshot` | `jsonb` | 收货地址快照 |
| `shipping_rule_snapshot` | `jsonb` | 模板版本、区域、计费数量/重量、附加费、包邮和分摊结果 |
| `expires_at` | `timestamptz null` | 待支付超时 |
| `paid_at` | `timestamptz null` | 支付完成 |
| `fulfill_by` | `timestamptz null` | 按交付类型计算的履约期限 |
| `auto_complete_at` | `timestamptz null` | 发货或交付后计算；售后中暂停 |
| `fulfillment_overdue_at` | `timestamptz null` | 首次标记履约超期时间 |
| `shipped_at` | `timestamptz null` | 发货时间 |
| `completed_at` | `timestamptz null` | 完成时间 |

约束：所有金额和积分非负；累计退款不超过实际支付；运费不参与积分和优惠券抵扣；`paid_wallet_amount` 与 `paid_external_amount` 不能同时大于零。

### `order_items`

订单、SKU、商品快照、规格快照、购买方式、履约类型、数量、单价现金/积分、优惠分摊、余额/外部渠道/积分实付、各来源累计已退款值。

### `inventory_reservations`

订单项、SKU、数量、状态 `reserved/consumed/released`、过期及消费/释放时间。待支付订单创建时增加预占，支付成功转正式扣减，取消或超时原子释放；纯积分订单建单后立即消费。

### `order_point_reservations`、`order_point_reservation_allocations`

订单积分预占总额及其 FIFO 积分批次分配，状态 `frozen/consumed/released`。汇总账户与每个批次同时锁行，保证累计冻结或扣减不超过可用余额。

### `order_status_changes`

订单状态只追加历史，保存变更前后状态、触发来源、操作者、原因、请求 ID 和时间，为支付回调、超时关闭、履约及售后提供审计依据。

### `fulfillments`

订单履约单、履约方式、状态、承运商、物流单号、无需物流说明、虚拟交付摘要和履约时间。只有实体物流要求承运商及物流单号；履约方式必须与订单一致。

### `fulfillment_items`

履约单关联的订单项和履约数量，支持同一交付类型订单分批履约；累计履约数量不能超过订单项购买数量。

### `fulfillment_tracking_events`

履约单、标准轨迹状态、渠道事件 ID、描述、发生时间、地点、查询渠道和原始状态摘要。`fulfillment_id + provider_event_id` 唯一；没有渠道事件 ID 时使用稳定事件摘要去重。

## 9. 支付与退款

### `payments`

| 字段 | 类型 | 规则 |
|---|---|---|
| `public_id` | `char(26)` | 对外支付 ID |
| `payment_no` | `varchar(32)` | 支付单号，唯一 |
| `order_id` | `bigint` | 订单 |
| `channel` | `varchar(24)` | `wechat` 等 |
| `scene` | `varchar(24)` | 小程序、微信内 H5、微信外 H5 |
| `cash_amount` | `bigint` | 应付现金分 |
| `status` | `varchar(32)` | 支付状态 |
| `provider_trade_no` | `varchar(128) null` | 第三方交易号，唯一 |
| `expires_at` | `timestamptz` | 过期时间 |
| `succeeded_at` | `timestamptz null` | 成功时间 |

第 19 批实现还包括用户、客户端场景、唯一幂等摘要、只供客户端调起的渠道参数、关单时间和订单状态联合索引。余额支付也生成支付单，但必须在同一数据库事务中完成全额扣款和订单资源结算。

### `payment_attempts`

支付单、递增尝试号、尝试状态、渠道请求 ID、脱敏请求/响应摘要及错误信息。每次调起或重试只追加记录，不保存商户私钥、APIv3 密钥、完整通知密文或用户 Token。

### `payment_notifications`

渠道、通知 ID、支付单、事件和交易状态、解密资源摘要、非敏感请求头摘要、处理状态、错误与时间。`provider + notification_id` 唯一；验签前不信任业务字段，验签解密后仍需核对支付单号、实付金额与合法状态转换。

### `reconciliation_runs`、`reconciliation_entries`、`reconciliation_actions`

按业务日期保存不可变对账批次、渠道/本地笔数金额汇总、逐笔匹配结果和财务处置动作。差异类型为 `missing_local/missing_provider/amount_mismatch/state_mismatch`；人工结论只追加原因与快照，不直接覆盖支付和订单事实。

### `refunds`

退款单号、订单、类型、原因、状态、应退现金、应退积分、申请人、审核人和幂等键。

### `refund_items`

退款单、订单项、退款数量、应退现金、应退积分及当时剩余可退快照。

### `refund_components`

组件类型 `cash/points/coupon/return_shipping`、金额或积分、状态、重试次数、第三方单号和错误信息。

### `return_shipments`

退款单、是否需要物流、物流公司、物流单号、付款凭证、责任方、用户垫付运费、最终承担金额、补偿金额、提交截止时间、审核状态和处理时间。无需物流时物流字段为空；申请运费补偿时物流单号和付款凭证必填。

### `after_sale_reasons`

售后原因代码、展示名称、默认责任方、允许的售后类型、原运费策略、退货运费承担方、是否需要凭证、补偿上限、启用状态和排序。实际退货的原运费策略固定为不退；未完成交付且无需退货的整单退款可以退。后台可以停用或调整规则，但历史售后保存原因和规则快照。

### `exchanges`、`exchange_shipments`

换货单号、订单项、同一 SKU、换货数量、原因、责任方、状态、寄回截止时间、用户寄回物流、补发物流、运费承担和规则快照。换货数量不得超过剩余可换数量；同一订单项进行中换货数量需并发锁定。

## 10. 其余基础表

- `addresses`：用户地址；订单保存快照。
- `files`：文件存储、访问级别和安全扫描结果。
- `notifications`、`notification_deliveries`：消息和各渠道发送记录。
- `notification_preferences`：用户渠道偏好、营销订阅和免打扰时间。
- `notification_templates`、`notification_template_versions`：各场景和渠道的模板草稿、已发布版本及变量定义。
- `operation_logs`：管理后台审计日志，不允许修改和删除。
- `idempotency_keys`：写接口请求摘要、结果和过期时间。
- `order_status_logs`：订单每次状态变化。
- `payment_callbacks`：支付回调去重、验签和处理摘要。

完整关系参见 [`ER_MODEL.md`](ER_MODEL.md)。
