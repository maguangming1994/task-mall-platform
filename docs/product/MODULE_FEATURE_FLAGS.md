# 功能模块开关（Module Feature Flags）

> 用途：远程控制小程序各功能模块的可用性，支持两类场景：
> 1. **微信审核期间**临时关闭敏感模块，审核通过后远程恢复，无需重新发版；
> 2. **按学校、分阶段灰度上线**——平台全局总闸门 + 校区级三态覆盖 + 阶段套餐，不同学校可在不同时间开放不同功能，而不是一口气全部上线。

## 一、开关清单与依赖关系

单一事实源：后端 `app/Modules/Operations/Support/ModuleRegistry.php`，前端镜像 `apps/user-app/src/platform/modules.ts`（两边 code/父链必须一致）。共 13 个模块：

| 开关 code | 模块 | 覆盖页面 | 父级依赖 | 分组 |
|---|---|---|---|---|
| `module_tasks` | 任务大厅 | 任务大厅 tab、任务详情、任务领取/提交 | — | 任务协作 |
| `module_task_publish` | 任务发布中心 | 发布中心、发布任务详情 | `module_tasks` | 任务协作 |
| `module_mall` | 商城浏览 | 积分商城 tab、商品详情 | — | 积分商城 |
| `module_mall_trade` | 商城交易 | 购物车、确认订单、订单列表/详情、支付、IM | `module_mall` | 积分商城 |
| `module_after_sale` | 售后服务 | 售后列表、申请售后、售后进度、退款/换货 | `module_mall_trade` | 积分商城 |
| `module_community` | 社区浏览 | 社区 tab、帖子详情、活动列表/详情 | — | 校园社区 |
| `module_community_post` | 社区发帖 | 发布帖子、评论点赞收藏、关注、发布活动/报名 | `module_community` | 校园社区 |
| `module_wallet` | 钱包资产 | 我的资产、钱包明细 | — | 资产钱包 |
| `module_wallet_recharge` | 余额充值 | 余额充值 | `module_wallet` | 资产钱包 |
| `module_withdraw` | 提现 | 提现申请 | `module_wallet` | 资产钱包 |
| `module_referrals` | 邀请推荐 | 我的推荐、邀请海报 | — | 增长工具 |
| `module_checkin` | 每日签到 | 每日签到 | — | 增长工具 |
| `module_second_hand` | 校园闲置 | 闲置列表/详情/发布/订单/评价/IM | — | 校园闲置 |

**依赖语义（父子链）**：任一祖先关闭，子模块即关闭。例如关 `module_mall` → `module_mall_trade`、`module_after_sale` 一并不可用；但只关 `module_mall_trade` 时用户仍可浏览商品，只是无法下单。前后端判定口径一致。

**不受控页面**（永远可用）：首页、登录、协议确认、实名认证、消息中心、收货地址、优惠券、联系客服、账号资料、文件上传、支付回调、举报/申诉/审核等。

## 二、两层模型：全局总闸门 × 校区三态覆盖

### 2.1 全局开关（平台级，既有能力）

存储于 `feature_flags` 表，模式三态：

- `enabled`：全局开放；
- `disabled`：全局关闭（**总闸门**，任何校区都不可用）；
- `percentage`：按 `sha256(模块+受众)` 哈希分桶灰度（仅用于平台级灰度，**审核场景禁用**）。

### 2.2 校区覆盖（`campus_feature_flags`，本次新增）

每个「校区 × 模块」一行三态：

| 覆盖状态 | 语义 |
|---|---|
| `inherit`（无行默认） | 跟随全局判定 |
| `enabled` | 该校强制开启（仅在全局非 disabled 时生效；全局 disabled 是总闸门，校区开不开） |
| `disabled` | 该校强制关闭，即使全局开启 |

**最终生效 = 全局总闸门优先 → 校区覆盖 → 全局灰度 → 父链兜底**：

1. 全局 mode 为 `disabled` 或记录缺失（fail-close）→ 关；
2. 否则校区 `enabled` → 开，`disabled` → 关；
3. `inherit` → 取全局（含 percentage 灰度按设备哈希）；
4. 父链上任一祖先最终关闭 → 关。

校区覆盖映射缓存 60s（key `campus:module-overrides:{campusId}`），任何写入后立即 forget；fail-close：未知模块 code 直接拒绝。

### 2.3 强制门禁

- 后端中间件 `module.gate:{code}`（`RequireModuleEnabled`）挂载于全部受控业务路由（公开列表/详情、登录组、发布者组、交易、钱包、社区互动、闲置等），关闭时返回 `403 MODULE_DISABLED`，消息为中性中文「{模块名}当前未开放，敬请期待。」，`details.module` 携带 code。
- 小程序 `GET /client-capabilities`（**version=5**）按 `X-Campus-Id` 合并下发 `feature_flags`（13 模块 + 3 实验键），并回传 `campus_id`。
- 前后端**双门禁**：入口隐藏 + 页面占位是体验层，路由中间件是安全层，绕过前端直接调 API 同样被拒。

## 三、阶段套餐

定义于 `app/Modules/Operations/Support/ModulePackage.php`：

| 套餐 | 内容 | 目标 |
|---|---|---|
| `basic` 基础版 | 任务大厅、校园社区（仅浏览）、每日签到 | 冷启动/试点期最小闭环 |
| `standard` 标准版 | basic + 商城浏览（`module_mall`）、校园闲置 | 社区活跃 + 种草浏览，不含交易/资金 |
| `full` 完整版 | 全部 13 模块（清空所有覆盖行，全部 inherit 跟随全局） | 全校完整运营 |

- 套餐外模块在 basic/standard 下被显式置 `disabled`；`full` 等价于「回到纯全局策略」。
- 套餐是**批量预设**，应用后仍可对单个模块微调（例如 standard 某校额外开放钱包）。
- 应用套餐走整体事务 + 一条汇总审计（`campus_module_package.applied`），仅状态真正变化的行递增 version。

## 四、后台操作 SOP

### 4.1 权限

- 矩阵入口：admin-web「学校与代理运营 → 学校库 → 功能模块」。
- **仅平台超级管理员**（新权限 `campus.module_manage`）可写；学校代理（campus_agent）只读：可查看本校矩阵，所有控件禁用。
- 写入接口挂 `admin.recent_verification`：会话二次验证过期时前端自动弹密码（+MFA）核验窗，核验后重试原操作。

### 4.2 界面读法（三层不混淆）

矩阵每一行展示：**平台全局** Tag（已开启/已关闭/灰度 N%）→ **该校策略**（继承全局/开启/关闭 Segmented）→ **最终生效** Tag（已开放/未开放；「校区开启但全局关闭」会带提示图标）→ 最近调整人与时间。

### 4.3 分学校灰度上线 SOP

1. 部署后端 + migrate，并执行 RbacSeeder（**不要 seed 覆盖行**，默认全部 inherit，现状零变化）；
2. 选定试点学校 → 应用 `basic` → 真机验证该校区仅显示基础三模块；
3. 稳定后升级 `standard`，按需为单个模块微调；
4. 全校推广时应用 `full`（覆盖清空，跟随全局）；
5. 新学校默认 inherit：只需调整全局开关即可控制所有未定制学校。

### 4.4 审核模式 SOP（全局）

**前置（一次性）**：`php artisan db:seed --class=ReleaseControlSeeder --force`（幂等）。

提交审核前，在「运营管理 → 功能开关」将建议项置 `disabled`：`module_wallet_recharge`、`module_withdraw`、`module_referrals`、`module_community_post`；如需保留商城仅展示则同时关 `module_mall_trade`。审核开关**只能用 disabled/enabled，禁止 percentage**（审核员设备可能哈希到开启侧）。审核通过后全部改回 `enabled`，用户冷启动即恢复。

> 校区覆盖与审核模式可叠加：全局关 = 所有学校关；若某学校因特殊原因需要在审核期保留功能，校区 enabled 也无法越过全局总闸门。

## 五、技术实现与缓存

### 后端（apps/api）

- 迁移 `2026_10_05_040000_create_campus_feature_flags`：`campus_id` 外键 cascadeOnDelete、`(campus_id, code)` 唯一索引、`status` 默认 inherit、`version` 乐观锁、`updated_by_admin_id` 置空 nullOnDelete。
- `ModuleGateService`：effective/allEffective（父链递归 memo，memo 按 request 实例隔离防跨请求污染）；campusId 解析优先登录用户 `campus_id`，否则 `X-Campus-Id`（仅限 active 校区）。
- 管理接口（admin 组，路由模型绑定校区 public_id + `admin.campus` 校区范围）：
  - `GET  /admin/campuses/{campus}/modules`：矩阵 + groups + 套餐目录（代理只读可调）；
  - `PUT  /admin/campuses/{campus}/modules/{code}`：body `status` + `expected_version`（乐观锁不符返 422 中文提示）+ 可选 `reason`；inherit 删行；审计 `campus_module.updated`；
  - `POST /admin/campuses/{campus}/module-packages/{package}`：应用套餐；审计 `campus_module_package.applied`。

### 小程序（apps/user-app）

- `src/stores/modules.ts`：缓存 key `task_mall_module_flags`，结构含 `campusId`；hydrate 仅复用与当前选中校区一致的缓存；`reloadForCampus()` 切校区立即清旧开关并强制拉取；fail-open 不变。
- `src/stores/campus.ts`：`setLocal()` 检测校区 id 变化后联动 `reloadForCampus()`（同校区品牌刷新不触发）。
- `src/api/http.ts`：403 且 `code=MODULE_DISABLED` 时调 `src/utils/moduleDisabled.ts` 的 `handleModuleDisabled(code)`：中性 toast + `uni.$emit('module:disabled')` + 强制刷新开关，随后照常 reject。
- 受控页统一三态渲染：`占位页（resolved 且关闭） / 骨架屏（未 resolved） / 真实内容`；27+ 页面在 onShow 走 `ensureReady()`，关闭时不发业务请求。
- `src/components/ModulePlaceholder.vue`：中性占位假页面（返回首页按钮）。

### admin-web（apps/admin-web）

- 学校库操作列「功能模块」打开矩阵抽屉：6 分组卡片、父子缩进、三态 Segmented、全局/最终生效双 Tag、套餐 Popconfirm 二次确认、乐观锁冲突自动重拉。

## 六、部署与回滚

**部署顺序**：

1. 后端发布并执行 `php artisan migrate --force`（只新增空表，不播种覆盖行，现有校区行为零变化）；
2. 执行 `php artisan db:seed --class=RbacSeeder --force`（**必须**：新增矩阵写权限 `campus.module_manage` 需同步给超级管理员角色；seeder 为 updateOrCreate + sync，幂等不改动既有角色配置）；
3. （首次或新模块）执行 `php artisan db:seed --class=ReleaseControlSeeder --force` 确保 13 个全局 flag 存在（缺失按 fail-close 视为关；生产 DatabaseSeeder 通常已含）；
4. admin-web 发布；
5. 后台按 4.3 配置试点学校；
6. 小程序发版（旧版小程序不消费校区维度，仅按全局行为运行，兼容共存）。

**冒烟**：部署后对现有校区分别请求任务/商城/社区/闲置各 1 个受控接口应保持 200；`client-capabilities` version=5 且 `feature_flags` 含 13 键。

**回滚**：

- 校区级误配：删除该校对应覆盖行（或矩阵改回「继承全局」）即恢复，缓存 60s 内或写入即时生效；
- 套餐误应用：重新应用目标套餐或逐行改回；
- 全局应急：「功能开关」置 enabled/disabled 即可一刀切；
- 代码回滚：新表留存无害（旧代码不读取）；需要彻底清理时 `migrate:rollback` 删表，不影响 `feature_flags` 与任何业务数据。
