# 功能模块开关（Module Feature Flags）

> 用途：远程控制小程序各功能模块的可用性。典型场景为**微信审核期间临时关闭敏感模块**，审核通过后远程恢复，全程无需重新发版。

## 一、开关清单与依赖关系

| 开关 code | 模块 | 覆盖页面 | 父级依赖 |
|---|---|---|---|
| `module_tasks` | 任务大厅 | 任务大厅 tab、任务详情、任务提交 | — |
| `module_task_publish` | 任务发布中心 | 发布中心、发布任务详情 | `module_tasks` |
| `module_mall` | 商城浏览 | 积分商城 tab、商品详情 | — |
| `module_mall_trade` | 商城交易 | 购物车、确认订单、订单列表/详情、支付结果 | `module_mall` |
| `module_after_sale` | 售后服务 | 售后列表、申请售后、售后进度 | `module_mall_trade` |
| `module_community` | 社区浏览 | 社区 tab、帖子详情、活动列表/详情 | — |
| `module_community_post` | 社区发帖 | 发布帖子、发布活动 | `module_community` |
| `module_wallet` | 钱包资产 | 我的资产 | — |
| `module_wallet_recharge` | 余额充值 | 余额充值 | `module_wallet` |
| `module_withdraw` | 提现 | 提现 | `module_wallet` |
| `module_referrals` | 邀请推荐 | 我的推荐、邀请海报 | — |
| `module_checkin` | 每日签到 | 每日签到 | — |

**依赖语义**：关闭父级开关，子级模块自动视为关闭。例如关闭 `module_mall` 后，`module_mall_trade` 和 `module_after_sale` 一并不可用；但开启 `module_mall`、单独关闭 `module_mall_trade` 时，用户仍可浏览商品，只是无法下单。

**不受控页面**（永远可用）：首页、登录、协议确认、协议与隐私、隐私与账号、我的、实名认证、消息中心、收货地址、联系客服。

## 二、关闭后的前端表现（不突兀的三层处理）

1. **tab 保留**：底部导航不隐藏，被关模块的 tab 点击进入**占位假页面**——校园风格插画图标 +「功能升级中」+「XX正在升级优化，稍作等待，精彩马上回来。」+「返回首页」按钮。文案中性合规，不提"审核/关闭/下线"。
2. **入口收敛**：首页金刚区/推荐区/商城区/资产与邀请卡片、我的页的服务菜单/钱包卡/订单卡/社区入口，按开关自动隐藏或整区收起，不留空位。
3. **直达兜底**：分享链接、扫码直接进入二级页时，页面自身守卫会显示占位页。

## 三、技术实现

### 后端（apps/api）
- `database/seeders/ReleaseControlSeeder.php`：12 个模块 flag，默认 `mode=enabled`、`rollout_percentage=100`（幂等 `firstOrCreate`）。
- `ClientCapabilitiesController`（`/api/v1/client-capabilities`）：`feature_flags` 中下发全部模块开关状态，`version=4`。
- 管理端：admin-web「运营管理 → 功能开关」直接操作（复用现有 `updateFeatureFlag`，带版本与审计）。

### 前端（apps/user-app）
- `src/platform/modules.ts`：模块注册表（code/名称/父级/图标）+ 首页 link_type 映射。
- `src/stores/modules.ts`：pinia store。`hydrate()` 同步恢复本地缓存；`ensureReady()` 页面 onShow 统一入口；`isEnabled(code)` 父级链检查。**fail-open**：接口失败/旧后端不下发时默认全部开启，不锁死用户。
- `src/components/ModulePlaceholder.vue`：占位假页面组件。
- 27 个受控页面统一三态渲染：`占位页（resolved 且关闭） / 骨架屏（未 resolved） / 真实内容（resolved 且开启）`，并在数据请求入口加守卫（关闭时不发业务请求）。
- `src/App.vue`：onLaunch 时 `hydrate()` + 后台强制刷新一次。

### 时序与缓存
- 缓存 key：`task_mall_module_flags`（不设过期，仅请求成功时覆盖）。
- 老用户：storage 同步命中，首帧即正确状态，零闪烁。
- 首装用户：骨架屏瞬现（接口正常 200-500ms）→ 直接渲染正确状态，不闪真实内容。
- 开关变更生效：冷启动强制刷新立即生效；运行中最坏 30s 节流窗口 + 一次页面 onShow。

## 四、操作指引（审核模式 SOP）

**前置（一次性）**：后端部署到生产后执行 `php artisan db:seed --class=ReleaseControlSeeder --force`。

**提交审核前**：admin-web → 运营管理 → 功能开关，将以下建议项置为 `disabled`：

| 建议关闭 | 理由 |
|---|---|
| `module_wallet_recharge` | 线上充值涉及支付合规 |
| `module_withdraw` | 提现是审核高危点 |
| `module_referrals` | 邀请裂变易被判定诱导分享 |
| `module_community_post` | UGC 发帖需内容安全能力证明 |
| `module_mall_trade` | 如需保留商城仅展示，可关交易保留浏览 |

保留开启：`module_tasks`（核心玩法）、`module_mall`（浏览）、`module_community`（浏览）、`module_wallet`（资产查看）、`module_checkin` 等——按实际审核要求灵活调整。

**重要**：审核开关**只能用 disabled / enabled 两态**，禁止使用 percentage 灰度模式（按设备哈希分桶，审核员设备可能落在开启侧）。

**审核通过后**：将全部开关改回 `enabled`，用户下次冷启动即恢复，无需重新发版。

## 五、回滚

- 前端：fail-open 设计，旧版本小程序完全不消费新开关，行为不变。
- 后端：仅新增 flag 与下发 key，回滚代码即可；已种子的 flag 留存无害。
