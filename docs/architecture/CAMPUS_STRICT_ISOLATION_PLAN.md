# 校区强隔离改造方案（用户绑定学校 · 只见本校内容）

> 版本：v1.3（补年级/专业/学院与三步绑定流程）　日期：2026-10-07
> 目标读者：产品、后端、小程序、管理后台、运维
> 关联文档：[MODULE_FEATURE_FLAGS.md](../product/MODULE_FEATURE_FLAGS.md)（校区×模块开关）、[ER_MODEL.md](../database/ER_MODEL.md)
>
> **决策记录（D1~D7 已于 2026-10-07 全部确认）**：登录只认服务端绑定校；匿名可全公开浏览、写操作必须登录绑校；UGC 禁止 NULL；商品一期平台统一目录；换校 30 天限频；老用户兜底默认校+强弹窗确认；按校 isolation_mode 灰度；平台投放一期仅全校、不做区域投放。

---

## 0. 一句话目标

把当前「平台全局内容共享 + 校区是一层可选滤镜」升级为「**用户必须绑定学校，登录后服务端只按其绑定校判定可见内容**；未登录访客可按所选学校浏览公开内容；UGC/交易/社区/闲置全校本闭环，仅保留少量显式标记的平台统一内容」。

---

## 1. 现状结论（代码实证）

| 事实 | 证据 | 后果 |
|---|---|---|
| `users.campus_id` 可空，注册不强制，仅当请求带 `X-Campus-Id` 才顺手写入 | `app/Models/User.php` creating 钩子 | 存在大量「无校用户」 |
| 内容表 `campus_id` 全部可空，NULL = 全局可见 | `..._add_campus_scope_to_business_tables.php` | 平台内容以 NULL 为主 |
| C 端全局作用域默认宽松：`campus_id IS NULL OR = 当前校` | `app/Modules/Campus/Models/Concerns/BelongsToCampus.php` | 本校用户看到大量外校/全局内容 |
| 不带 `X-Campus-Id` 头时作用域直接跳过 | 同上 + `ResolveCampusContext.php` | 无头请求可见**全表** |
| 头由客户端自带、可伪造，且不与登录绑定校校验 | `ResolveCampusContext.php` | 改头即可看任意校内容 |
| strict 模式仅管理端学校代理开启 | `EnforceAdminCampusScope.php` | C 端从来不是强隔离 |
| `PUT /me/campus` 可随时切任意 active 学校，无门槛 | `CampusController::select` | 换校即窜数据口径 |
| 管理端隔离已完备 | 同上中间件 | 可直接沿用 |

**基础盘点**：核心内容表（tasks/products/orders/community_posts/community_activities/second_hand_items）已有 `campus_id`；缺的是**强制绑定、C 端严格口径、NULL 数据治理**三件事，不是重建模型。

---

## 2. 关键产品决策（D1~D7 已于 2026-10-07 全部确认）

| # | 决策点 | 最终方案 | 状态 |
|---|---|---|---|
| D1 | 登录用户校区依据 | **服务端 `users.campus_id` 为唯一事实源**，客户端 `X-Campus-Id` 对登录态无效 | ✅ 已确认 |
| D2 | 未登录访客 | **可全公开浏览**：带头可选校浏览任务/商品/社区/闲置的公开列表与详情；任何写操作（领取/下单/发帖/发布闲置/充值等）必须登录且已绑校 | ✅ 已确认 |
| D3 | NULL 全局内容 | **UGC（社区/活动/闲置）禁止 NULL**；`tasks` 的 NULL 仅用于显式「平台投放」（配合 `is_platform_wide=true`） | ✅ 已确认 |
| D4 | 商城商品 | **一期保留平台统一商品目录**（平台统一供货、各校所见相同），订单仍按绑定校归校；二期校本货架（`campus_products`）单独立项，另估 8~12 人日 | ✅ 已确认 |
| D5 | 换校规则 | **自助换校 30 天 1 次**（系统设置可调）+ 冷启动生效；余额/订单/优惠券随人，UGC 留原校；未履约闲置订单未完结前禁止换校；管理员强制改校不限频、需 reason、写审计 | ✅ 已确认 |
| D6 | 存量无校用户 | 兜底挂默认学校 (`default-campus`)，**首次登录强弹窗：确认学校或重新选择，不完成不能进入业务页** | ✅ 已确认 |
| D7 | 隔离灰度方式 | 按校 `campuses.isolation_mode`（legacy/strict）灰度，可随时切回 legacy，无需回滚代码 | ✅ 已确认 |
| D8 | 平台投放范围（追加） | **一期仅全校投放**（全部学校可见或校本任务二选一）；省/市区域投放需另建 `task_campus_targets` 关联表，不在本期 | ✅ 已确认 |

> 「平台投放任务」：由平台运营创建、面向所有学校的官方任务（新生引导、平台活动）。它是唯一被允许跨校可见的任务类内容，必须带显式投放标记，而非历史遗留的裸 NULL。

---

## 3. 目标数据模型

### 3.1 归属总则

```
登录用户  → 服务端 users.campus_id（唯一），记为 SCOPE
匿名访客  → X-Campus-Id 解析值，记为 SCOPE（仅公开读，禁止任何写）
无 SCOPE → 除白名单接口外，业务查询一律拒绝/空集（fail-close）
```

内容可见口径（C 端）：

| 内容类型 | 可见条件（★方案） |
|---|---|
| 社区帖子/活动、闲置物品 | `campus_id = SCOPE`，**严格相等，无 NULL 兜底** |
| 校本任务 | `campus_id = SCOPE` |
| 平台投放任务 | 显式平台投放标记（跨校可见，D3） |
| 商品目录 | 平台统一目录（D4 例外，一期跨校相同） |
| 订单/售后/购物车/钱包/流水/签到/邀请/地址/IM | 按 `user_id` 私有；其校区随用户绑定校，管理端按校统计时经 users 关联 |
| 闲置订单/IM | 跟随卖家/物品校区（建议冗余 campus_id，便于风控与后台按校检索） |

### 3.2 表结构变更（新增/调整）

| 表 | 变更 | 说明 |
|---|---|---|
| `campuses` | 加 `isolation_mode` varchar(16) 默认 `legacy`（`legacy`/`strict`） | 按校灰度开关（D7），也可只放系统设置白名单，二选一，推荐落表可审计 |
| `tasks` | 加 `is_platform_wide` boolean 默认 false（索引） | 显式平台投放标记；平台任务 `campus_id` 置 NULL 且此标为 true |
| `second_hand_orders`、`chat_conversations` | 加 `campus_id` nullable + 索引 | 冗余归属，随下单/建会话写入，后台按校管理 |
| `campus_membership_changes`（新表） | user_id、from_campus_id、to_campus_id、reason、changed_by_admin_id、created_at | 换校审计（D5） |
| `users` | 语义收紧：业务上 `campus_id` 必填（DB 暂保留 nullable 以兼容灰度，由中间件强约束）；**新增 `grade_year` int（入学年份，必选）、`major` varchar(30)（专业名称，必填）、`college` varchar(60)（学院，选填）** | 档案三字段与校区解耦，换校不丢失；灰度全量后可再上严格约束 |
| UGC 表 | `campus_id` 业务必填；写入侧强制取绑定校 | 灰度后禁止新 NULL |

---

## 4. 后端改造（apps/api）

### 4.1 统一校区口径（核心，替换现有双口径）

现状「内容取请求头、模块门禁取绑定校」必须合一：

1. 新增 `app/Modules/Campus/Http/Middleware/ResolveCampusScope.php`，注册顺序在 `auth.optional`/`auth.access` **之后**（或在其内部解析）：
   - 已登录：`SCOPE = user.campus_id`（**忽略 X-Campus-Id**）；`bound = (SCOPE !== null)`；
   - 匿名：沿用 `X-Campus-Id` 解析 SCOPE，`bound=false`、`authenticated=false`；
   - 写入 request attributes：`campus_database_id`、`campus_scope_bound`、`campus_scope_authenticated`、`campus_strict`（该校是否 strict 模式）。
2. 登录用户头与绑定校不一致时，不报错，但**一律以绑定校为准**（capabilities 已是此逻辑，推广到全量接口）。
3. 保留 `ResolveCampusContext` 仅服务匿名场景；二者合并为一个中间件更佳（推荐直接重构该中间件，避免重复解析）。

### 4.2 三个守卫分层

| 层 | 机制 | 行为 |
|---|---|---|
| L1 绑定校验 | 新中间件 `RequireBoundCampus`（别名 `campus.bound`） | 已登录但未绑校 → `403 CAMPUS_NOT_BOUND`，附默认校与选校入口；匿名不放行需登录组 |
| L2 查询隔离 | 改造 `BelongsToCampus` 全局作用域 | `campus_strict=true` 且已登录 → `where campus_id = SCOPE`（无 NULL 兜底）；匿名公开读 → `campus_id = SCOPE`；平台投放/商品目录走显式豁免（4.3）；无 SCOPE → fail-close（加永假条件） |
| L3 写入门禁 | 创建模型时取绑定校 | UGC/任务写入的 `campus_id` 一律取 `user.campus_id`，忽略客户端任何传入；未绑定拒绝 |

### 4.3 平台投放与商品目录的显式豁免

在作用域内对**特定模型**放开，而不是沿用 NULL 黑洞：

- `Task`：`where campus_id = SCOPE OR (is_platform_wide = true)`；其余模型不加此支；
- `Product`/`ProductCategory`：一期不挂 `BelongsToCampus` 的 strict 支（目录级跨校），但**订单写入仍盖 `campus_id = SCOPE`**；在代码与文档显式标注其为例外，二期用 `campus_products`（校本上下架、校本库存/价）替换。

> 原则：豁免必须是「模型级白名单 + 显式标记」，禁止继续用「campus_id IS NULL 一律可见」这种隐式规则。

### 4.4 接口守卫分组（路由改造清单）

| 分组 | 现状中间件 | 目标 |
|---|---|---|
| 校区选择/列表、nearby | 公开 | 保持公开（选校需要） |
| 登录/注册/验证码/微信授权/协议/法律文档 | 公开 | 保持公开 |
| `/client-capabilities` | auth.optional | 保持；返回 `campus_required` 标志供小程序拦截 |
| 健康检查、支付回调、上传回调、申诉/举报/客服 | 公开/特定 | 保持（上传需登录但不强绑，供头像等） |
| 实名认证、账号注销、账号资料、消息中心、地址、钱包只读 | auth | **不加** bound（未绑校也要能实名/找客服/看资产） |
| 任务大厅/详情、商品列表/详情、社区浏览、闲置浏览 | auth.optional + module.gate | 加 SCOPE 解析；登录按绑定校 strict，匿名按头；无 SCOPE 返回空集而非全表 |
| 任务领取/提交、下单/支付/售后、发帖/评论/活动报名、闲置发布/下单/评价、充值/提现/签到/邀请 | auth + module.gate | **加 `campus.bound`**；写入盖绑定校 |
| `PUT /me/campus` | auth | 保留，加换校限频校验（D5） |
| admin 组 | auth.admin + admin.campus | 不变（已 strict） |

实施：在 `routes/api.php` 用一个 `campus.bound` 中间件组包裹「登录后业务写 + 校本私有读」；公开浏览组只挂 SCOPE 解析不挂 bound。

### 4.5 换校接口（D5）

`PUT /me/campus` 增加：

- 距上次换校 < 配置天数（默认 30，系统设置 `campus.change_cooldown_days`）→ `422 CAMPUS_CHANGE_TOO_FREQUENT`；
- 记录 `campus_membership_changes`；
- 成功后返回新校区，客户端清全部业务缓存并冷启动（复用现有切校区 `reloadForCampus()`）；
- 运营后台提供「管理员强制改校」入口（不受限频，带 reason 审计）。

**年级/专业/学院随账号保留**：换校仅改 `campus_id`，`grade_year`、`major`、`college` 三字段不变；用户可在个人资料页自行修改专业（一年级学生转专业场景），修改无冷却。

换校后数据归属：

- 余额、流水、充值/提现、优惠券、订单、售后、地址：**随账号保留**（历史可查，订单仍标记原校）；
- 已发帖子/活动/闲置：**留在原校**（按 campus_id），在新校不可见；提供「我的发布」跨校查看自己的历史；
- 进行中的闲置订单：履约完毕前不允许换校（或弹窗提示有未完成订单）。

---

## 5. 存量数据治理（上线前最关键的一步）

提供一个幂等 artisan 命令：`php artisan campus:backfill --dry-run`，先出报告再执行。

### 5.1 用户回填

1. `users.campus_id IS NULL`：
   - 优先从其最近订单/发布/地址等推断校区；
   - 无法推断 → 挂 `default-campus`（D6）；
2. 给被兜底用户打标（新增临时标志或直接依赖换校历史），下次登录强制弹「确认你的学校 / 重新选择」。

### 5.2 UGC 回填（禁止保留 NULL）

- `community_posts/community_activities/second_hand_items.campus_id IS NULL`：
  - 取作者 `users.campus_id`；
  - 作者也无校 → 先回填作者到默认校，再继承；
  - 作者已注销/找不到 → 默认校 + 标记待运营审核（可先下架私密态）。
- 回填后做**重复/冲突校验**：同一帖子作者校与帖子校不一致的出报告。

### 5.3 任务与商品

- 历史 NULL 任务：逐条判定——平台活动类 → `is_platform_wide=true`；校本误录 → 回填对应校；无法判定 → 默认下线（draft）由运营复核；
- 商品：一期不动（平台目录例外）；如二期选校本货架，再出 `campus_products` 映射迁移。

### 5.4 校验（执行后必须为 0）

```sql
-- strict 模式启用前，UGC 不允许 NULL
select count(*) from community_posts where campus_id is null;
select count(*) from second_hand_items where campus_id is null;
-- 登录业务用户不允许无校（白名单：未完成注册的草稿账号除外）
select count(*) from users where campus_id is null and status = 'active';
-- 孤立归属：内容指向已停用/删除学校
select count(*) from community_posts p left join campuses c on c.id=p.campus_id where c.id is null;
```

---

## 6. 管理后台改造（apps/admin-web）

大部分隔离已由 `EnforceAdminCampusScope` + strict 作用域完成，补充：

1. **学校库**：学校编辑加「隔离模式（灰度/传统）」开关（超管可见）；
2. **用户管理**：用户详情显示绑定校 + 「管理员强制改校」操作（填 reason、不受限频、写审计）；无校用户筛选项；
3. **内容列表**（任务/社区/闲置/订单）：代理已只能看本校；超管增加「平台投放/全校」筛选；任务编辑加「平台投放任务」勾选（勾选后校区选择禁用）；
4. **数据看板**：所有按校统计明确口径（经 users 关联私有数据），避免把全局商品/平台任务重复计入各校；
5. 沿用模块矩阵的二次验证 + 乐观锁交互范式。

---

## 7. 小程序改造（apps/user-app）

| 场景 | 改造 |
|---|---|
| 启动/登录 | 拉 capabilities，若 `campus_required=true` → 进入全屏选校/确认校页，未完成不放行业务 tab（复用现有 `CampusSelector` + 选校页） |
| 被兜底默认校的老用户 | 首次登录弹窗「你当前被归入默认学校，请确认或重新选择」，**同时强制补全年级、专业（选填学院），三步骤完成前不得进入业务页** |
| 请求头 | 继续带 `X-Campus-Id`（匿名浏览需要）；登录态以服务端为准，头不一致时以后端返回的 campus 校正本地 store |
| HTTP 403 `CAMPUS_NOT_BOUND` | 全局拦截 → 跳选校页（与现有 `MODULE_DISABLED` 拦截同一层处理） |
| 换校 | 入口加限频文案与冷却倒计时；成功后清业务缓存并重启首页数据；**年级/专业/学院随账号保留** |
| 内容页 | 严格模式下列表为空显示「本校暂无内容」，不再混入全局 |
| 发布类页面 | 打开发布时若未绑校或**档案不完整（grade_year/major 为空）**直接拦截至绑定页；发布成功后内容确定归当前绑定校 |

**三步绑定流程（M1/M1b/M1c 已定稿）**：

1. **学校**：搜索/定位/列表选校（复用现有组件），一次只能选 1 所；
2. **年级**：网格选择「2026 级 / 2025 级 / 2024 级 / 2023 级 / 2022 级 / 其他」，选「其他」弹层手动输入 1900~当年区间年份；
3. **专业**：手动输入（≤30 字），「暂未确定」复选框可跳过；选填学院（≤60 字）；
4. 三步骤数据一次提交至 `PUT /me/campus`，服务端校验 campus 存在且 active、grade_year 在合理区间、major 非空（除非勾选暂未确定）。

---

## 8. 测试方案（必须新增的越权矩阵）

后端 Feature 测试（sqlite in-memory，建 A/B 两校 + 各自用户/内容）：

| 用例 | 预期 |
|---|---|
| A 校登录用户请求列表 | 只见 A 校 + 平台投放任务，不见 B 校任何 UGC/任务 |
| A 校 token 直接访问 B 校内容 public_id（任务/帖子/闲置/订单） | 404（作用域查不到），不是 403/200 |
| A 校 token 带 `X-Campus-Id: B校` | 内容仍是 A 校（头对登录态无效） |
| 已登录未绑校访问业务写接口 | 403 CAMPUS_NOT_BOUND；选校/实名/客服不受影响 |
| 匿名带 A 校头 | 只见 A 校公开内容；无头 → 空集（非全表） |
| 匿名尝试写接口 | 401 |
| UGC 发布伪造 campus_id 字段 | 被忽略，强制盖绑定校 |
| 换校 30 天内再次换 | 422；管理员强制改校成功且写审计 |
| 换校后旧校帖子在新校不可见、「我的发布」仍可见 | 通过 |
| 平台投放任务两校均可见；普通校本任务仅本校 | 通过 |
| 商品目录两校一致（一期例外）；订单校区=下单时绑定校 | 通过 |
| 管理端代理访问非授权校 | 403 CAMPUS_SCOPE_DENIED（回归现有） |
| **登录用户 campus_id 非空但 grade_year 或 major 为空，访问业务写接口** | **403 CAMPUS_PROFILE_INCOMPLETE（档案不完整），提示补全年级/专业** |
| **档案完整用户换校后，grade_year/major/college 三字段保持原值** | **通过** |
| **管理端强制改校时可同步修改年级/专业/学院（选填）** | **通过** |

迁移命令测试：dry-run 报告准确、执行幂等、重复执行不产生二次回填、校验 SQL 清零。

---

## 9. 分阶段上线与灰度（D7）

| 阶段 | 内容 | 风险控制 |
|---|---|---|
| P0 口径统一（不改可见性） | 新中间件按登录绑定校解析 SCOPE（capabilities 已如此）；先只统一口径，作用域仍宽松；上线观察 | 行为几乎不变，可随时关 |
| P1 数据治理 | `campus:backfill --dry-run` 出报告 → 评审 → 执行；校验 SQL 清零；补换校历史表、平台投放标记、闲置单冗余字段 | 先备份；分批按校回填 |
| P2 强制绑定 + UGC strict | 选校门禁、`campus.bound`、UGC 写入取绑定校；先对 1~2 个**试点校**置 `strict`，UGC 严格、其余内容按 4.3 | 试点校可单独回退 legacy |
| P3 任务/浏览全 strict + 换校限频 | 任务平台投法语义生效、匿名无头 fail-close、换校限频 | 按校扩大名单 |
| P4 全量 | 全部学校置 strict；观察期后将 users.campus_id 收紧为 DB 非空；评估二期校本货架 | 保留开关一个版本周期 |

灰度实现：`campuses.isolation_mode`（或系统设置白名单）控制作用域分支，legacy 走旧宽松 SQL，strict 走新规则；发布与回滚都只是切数据开关，无需回滚代码。

**回滚预案**：

- 单校异常：该校 `isolation_mode=legacy`；
- 全局异常：系统开关关闭 strict；
- 回填错误：回填命令同时输出映射表（用户/内容 → 原校区、原状态），保留回滚 SQL/命令；
- 新表/新字段均为增量，旧代码不读取，回滚代码无数据损伤（与模块开关上线同策略）。

---

## 10. 工作量初估（人日，含自测，不含二期货架）

| 模块 | 估算 |
|---|---|
| 后端：SCOPE 中间件 + 三层守卫 + 路由分组改造 | 2 |
| 后端：换校限频/历史/归属规则 | 1.5 |
| 后端：平台投放标记、闲置单冗余字段与回填 | 1 |
| 数据治理命令（dry-run/执行/校验/回滚映射） | 2 |
| 后端测试（越权矩阵 + 命令测试） | 2 |
| 管理后台：隔离模式、强制改校、平台投放勾选、口径修正 | 2 |
| 小程序：选校门禁、403 拦截、换校交互、空态 | 2.5 |
| 联调 + 灰度观察 + 文档更新 | 2 |
| **合计** | **约 15 人日**（二期校本货架另估约 8~12 人日） |

---

## 11. 决策状态

**D1~D8 已于 2026-10-07 全部确认（见第 2 节）**，方案进入可实施状态。

**唯一待定（排期项，不阻塞 P0/P1 开发）**

- 灰度试点校名单与各阶段时间点（P2 启用前由运营指定即可，P0 口径统一、P1 数据治理可先行）。
