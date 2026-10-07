# 校区强隔离 P0+P1 实施清单

> 版本：v2（补 users 三字段迁移、档案完整性判定、换校保留年级专业、测试用例补档案完整）　日期：2026-10-07
> 依据：[CAMPUS_STRICT_ISOLATION_PLAN.md](CAMPUS_STRICT_ISOLATION_PLAN.md) v1.2（D1~D8 已定稿）
> 范围：P0（口径统一，不改可见性）+ P1（存量数据治理）全部后端落点；P2（守卫/拦截）的前置代码与开关也在本清单内交付、默认关闭。
> 工作分支建议：`feat/campus-isolation-p0p1`，与主工作区并行 WIP 隔离。

---

## 1. 交付物总览

| 序 | 交付物 | 类型 | 默认状态 |
|---|---|---|---|
| M1 | `campuses.isolation_mode`（legacy/strict 默认 legacy）+ `tasks.is_platform_wide`（默认 false）+ `second_hand_orders.campus_id`、`chat_conversations.campus_id` 冗余字段 | 迁移 | 增量、旧代码不读，可回滚 |
| M1b | `users.grade_year` int NULL + `users.major` varchar(30) NULL + `users.college` varchar(60) NULL | 迁移 | 增量、旧代码不读，可回滚 |
| M2 | `campus_membership_changes` 换校审计表 | 迁移 | 增量 |
| C1 | `ResolveCampusScope` 中间件（统一口径：登录=绑定校、匿名=头、无头=fail-close 标记） | 代码 | P0 即生效（口径统一，可见性不变） |
| C2 | `BelongsToCampus` 增加 strict 分支 + 豁免规则 | 代码 | 仅 strict 校区走新分支，legacy 完全不变 |
| C3 | Task 平台投放豁免（`campus_id=SCOPE OR is_platform_wide`）、Product 目录显式豁免 | 代码 | 随 C2 |
| C4 | `campus:backfill` 命令（--dry-run 报告 / 执行 / 校验 / 回滚映射） | 命令 | 手动执行 |
| T1 | 越权与口径测试（A/B 双校矩阵） | 测试 | 随 C1~C3 |

---

## 2. 迁移设计（apps/api/database/migrations）

### 2.1 迁移文件一：`2026_10_xx_000001_add_isolation_fields.php`

```php
// campuses：隔离模式（legacy=旧宽松，strict=新严格）
Schema::table('campuses', function (Blueprint $table) {
    $table->string('isolation_mode', 16)->default('legacy')->after('status');
});

// tasks：平台投放标记（跨校可见的唯一合法例外）
Schema::table('tasks', function (Blueprint $table) {
    $table->boolean('is_platform_wide')->default(false)->after('campus_id');
    $table->index(['is_platform_wide', 'campus_id']);
});

// 闲置订单/IM 会话：冗余校区归属，便于后台按校检索与风控
Schema::table('second_hand_orders', function (Blueprint $table) {
    $table->unsignedBigInteger('campus_id')->nullable()->after('id');
    $table->index('campus_id');
});
Schema::table('chat_conversations', function (Blueprint $table) {
    $table->unsignedBigInteger('campus_id')->nullable()->after('id');
    $table->index('campus_id');
});

// users：校园档案三字段（与校区解耦，换校不丢失）
Schema::table('users', function (Blueprint $table) {
    $table->unsignedSmallInteger('grade_year')->nullable()->after('campus_id'); // 入学年份，如 2025
    $table->string('major', 30)->nullable()->after('grade_year');               // 专业名称，手动填写
    $table->string('college', 60)->nullable()->after('major');                  // 学院名称，选填
});
```

`down()` 逐项 drop，均不破坏旧代码（旧代码不读新列）。

### 2.2 迁移文件二：`2026_10_xx_000002_create_campus_membership_changes.php`

```php
Schema::create('campus_membership_changes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id')->index();
    $table->unsignedBigInteger('from_campus_id')->nullable();
    $table->unsignedBigInteger('to_campus_id');
    $table->string('reason')->nullable();                    // 用户自助/管理员强改/兜底回填
    $table->unsignedBigInteger('changed_by_admin_id')->nullable(); // 管理员操作时为非空
    $table->timestamps();
});
```

---

## 3. C1：ResolveCampusScope 中间件

**文件**：`app/Modules/Campus/Http/Middleware/ResolveCampusScope.php`（新）
**注册**：`bootstrap/app.php` 中追加到 api 中间件组，放在 `auth.optional`/`auth.access` **之后**；原 `ResolveCampusContext` 只保留匿名解析职责或直接重构进本中间件（推荐后者，避免双解析）。

**解析逻辑（伪代码）**：

```php
public function handle($request, Closure $next)
{
    $user = $request->user(); // auth.optional 之后可取
    $bound = false;

    if ($user) {
        // D1：登录态只认服务端绑定校，忽略 X-Campus-Id
        $campusDbId = $user->campus_id;
        $bound = $campusDbId !== null;
    } else {
        // D2：匿名按请求头解析（原有 ResolveCampusContext 逻辑迁移至此）
        $campusDbId = $this->resolveFromHeader($request); // null = 无头
    }

    $campus = $campusDbId ? Campus::find($campusDbId) : null;

    $request->attributes->set('campus_database_id', $campusDbId);       // 兼容现有读取点
    $request->attributes->set('campus_scope_authenticated', (bool) $user);
    $request->attributes->set('campus_scope_bound', $bound);
    $request->attributes->set('campus_strict', ($campus?->isolation_mode === 'strict'));

    return $next($request);
}
```

**兼容要点**：
- 现有读取 `campus_database_id` attribute 的代码（BelongsToCampus、capabilities 等）无需改；
- 登录态头与绑定校不一致：以绑定校为准，不报错；响应头可回写 `X-Campus-Resolved` 供小程序校正本地 store（C1 内顺手做）；
- `GET /client-capabilities` 增加返回字段 `campus_required: bool`（登录 && （未绑校 || 档案不完整：grade_year 或 major 为空）为 true），小程序据此弹选校/补档案门禁。

---

## 4. C2：BelongsToCampus strict 分支

**文件**：`app/Modules/Campus/Models/Concerns/BelongsToCampus.php`

```php
static::addGlobalScope('campus', function (Builder $builder) {
    $request = request();
    $strict  = (bool) $request->attributes->get('campus_strict', false);
    $scope   = $request->attributes->get('campus_database_id');

    // legacy：完全保留现状（NULL 或当前校；无头不加条件）
    if (!$strict) {
        if ($scope !== null) {
            $builder->where(function ($q) use ($scope, $builder) {
                $q->whereNull($builder->qualifyColumn('campus_id'))
                  ->orWhere($builder->qualifyColumn('campus_id'), $scope);
            });
        }
        return;
    }

    // strict：fail-close
    if ($scope === null) {
        $builder->whereRaw('1 = 0'); // 无校区上下文 → 空集
        return;
    }
    $builder->where($builder->qualifyColumn('campus_id'), $scope); // D3：UGC 无 NULL 兜底
});
```

**豁免（C3）**——用静态豁免表替代硬编码散落：

```php
// 模型级豁免：目录类不参与 strict（D4）
protected static bool $campusScopeExempt = false; // BelongsToCampus 默认 false
// Product / ProductCategory 模型里设 true，并加注释「平台统一目录例外，D4」

// Task：平台投放豁免
if ($builder->getModel() instanceof Task) {
    $builder->where(function ($q) use ($scope) {
        $q->where('campus_id', $scope)->orWhere('is_platform_wide', true);
    });
}
```

> 实现上建议在 trait 内提供 `applyCampusScope($builder, $scope)` 受保护方法，Task 模型覆写追加 `is_platform_wide` 支，避免 instanceof 判断。

---

## 5. 写入侧改造（盖绑定校，忽略客户端传入）

| 位置 | 改动 |
|---|---|
| 社区发帖/活动创建、闲置发布（SecondHandItemController 等 UGC store） | `campus_id` 一律 `$user->campus_id`；未绑校或档案不完整（grade_year/major 为空）→ 403 `CAMPUS_NOT_BOUND`/`CAMPUS_PROFILE_INCOMPLETE`（复用 P2 的 bound 检查，提前在此落地） |
| 任务领取/提交、下单 | 领取/下单记录盖 `$user->campus_id`；orders 现有盖章逻辑核对为绑定校而非头 |
| second_hand_orders / chat_conversations 创建 | 冗余 `campus_id = 物品.campus_id`（新列） |
| 商品/分类后台维护 | 不加 campus 字段（D4 目录例外） |

**接口错误码**：

- `403 CAMPUS_NOT_BOUND`：未绑校，响应体 `{ code: 'CAMPUS_NOT_BOUND', data: { default_campus_public_id, select_url } }`；
- `403 CAMPUS_PROFILE_INCOMPLETE`：已绑校但档案不完整（grade_year 或 major 为空），响应体 `{ code: 'CAMPUS_PROFILE_INCOMPLETE', data: { missing_fields: ['grade_year','major'], bind_url } }`。

---

## 6. C4：campus:backfill 命令

**文件**：`app/Console/Commands/CampusBackfill.php`，签名 `campus:backfill {--dry-run} {--only=users|ugc|tasks}`，注册到 console kernel。

### 6.1 处理顺序与推断规则（幂等）

```
Step 1 用户回填（users.campus_id IS NULL）
  1.1 取最近一条订单/收货地址/已领取任务/发布内容的校区众数 → 回填
  1.2 无法推断 → default-campus，reason=backfill_default 写 campus_membership_changes
  1.3 全部回填用户打「待确认」标记：campus_membership_changes 留痕即可，
      小程序用「该用户存在 reason=backfill_default 且无后续确认记录」触发强弹窗（D6）
  1.4 **grade_year / major / college 不自动回填**：由 M2 弹窗强制用户自填，backfill 不碰这三字段

Step 2 UGC 回填（community_posts / community_activities / second_hand_items）
  2.1 campus_id IS NULL → 作者 users.campus_id
  2.2 作者无校（不应出现，Step1 已清）→ default-campus
  2.3 帖子校 ≠ 作者校 的存量行 → 出报告，不改数据，由运营复核

Step 3 任务与冗余字段
  3.1 tasks.campus_id IS NULL：
      - 可判定平台活动（运营名单配置传入 --platform-tasks=1,2,3）→ is_platform_wide=true
      - 可判定校本（发布者/publisher 归属校）→ 回填
      - 无法判定 → status=draft 下线 + 报告
  3.2 second_hand_orders.campus_id ← 关联 second_hand_items.campus_id
      chat_conversations.campus_id ← 同上

Step 4 校验（全部为 0 才算成功，非 0 退出码 1）
  select count(*) from community_posts where campus_id is null;
  select count(*) from second_hand_items where campus_id is null;
  select count(*) from users where campus_id is null and status='active';
  -- 孤立归属
  select count(*) from community_posts p left join campuses c on c.id=p.campus_id where c.id is null;
```

### 6.2 dry-run 报告格式

控制台表格 + 落盘 `storage/app/backfill/{YmdHis}-report.json`：

```json
{
  "users": { "total_null": 120, "inferred": 95, "to_default": 25 },
  "ugc": { "posts_null": 40, "items_null": 12, "conflicts": 3 },
  "tasks": { "platform_wide": 5, "campus_backfill": 18, "to_draft": 2 },
  "redundant": { "second_hand_orders": 88, "chat_conversations": 60 }
}
```

### 6.3 回滚映射

执行时同步写 `storage/app/backfill/{批次号}-rollback.json`：`{table, id, old_campus_id, old_status}` 明细；配套 `campus:backfill-rollback {batch}` 按映射还原（P1 交付，便于治理失败兜底）。

---

## 7. 换校接口改造（D5，P1 一并落地）

`CampusController::select`（PUT /me/campus）：

1. 未履约闲置订单检查：存在 `second_hand_orders` 状态 ∈ {pending, paid, shipped} 且属于当前用户 → `422 CAMPUS_CHANGE_BLOCKED_ORDERS`；
2. 限频：最近一次 `campus_membership_changes`（非管理员强改）距现在 < `campus.change_cooldown_days`（系统设置，默认 30）→ `422 CAMPUS_CHANGE_TOO_FREQUENT`，响应附 `next_available_at`；
3. 成功：写 `users.campus_id` + `campus_membership_changes`（reason=self）；**`grade_year`/`major`/`college` 保持原值，不在本次接口中修改**；响应返回新校区 + `require_restart: true`；
4. 管理端强制改校接口：`PUT /admin/users/{id}/campus {campus_id, reason, grade_year?, major?, college?}`，超管/有用户管理权限，不限频，`changed_by_admin_id` 非空，可同时修正档案。

---

## 8. T1 测试清单（Feature，sqlite :memory:）

新建 `tests/Feature/CampusIsolationTest.php`（P0 口径用例立即通过；strict 用例对 A 校置 strict 后断言）：

| # | 用例 | 预期 |
|---|---|---|
| 1 | 登录用户带头(≠绑定校)请求列表 | 返回按**绑定校**（头无效） |
| 2 | 匿名带头=A 校 | 见 A 校公开内容；legacy 下含 NULL，strict 下无 NULL |
| 3 | 匿名无头，strict 校区上下文 | 空集（fail-close），非全表 |
| 4 | 登录未绑校访问 UGC 写接口 | 403 CAMPUS_NOT_BOUND |
| 5 | 平台投放任务（is_platform_wide）在 A/B strict 校均可见；普通校本任务仅本校 | 通过 |
| 6 | 商品目录 strict 下两校一致（D4 豁免）；订单 campus_id=下单绑定校 | 通过 |
| 7 | UGC 发布伪造 campus_id 字段 | 被忽略，实际=绑定校 |
| 8 | 换校 30 天内再次换 | 422 + next_available_at |
| 9 | 有未履约闲置订单时换校 | 422 CAMPUS_CHANGE_BLOCKED_ORDERS |
| 10 | 管理员强制改校 | 成功，changed_by_admin_id 非空，审计留痕 |
| 11 | backfill --dry-run 报告计数准确；执行幂等（二次执行 0 变更）；校验清零 | 通过 |
| 12 | capabilities 返回 campus_required（登录未绑校=true；登录且档案不完整=true） | 通过 |
| 13 | 档案不完整用户（campus_id 非空但 grade_year 或 major 为空）访问 UGC 写接口 | 403 CAMPUS_PROFILE_INCOMPLETE |
| 14 | 档案完整用户换校后，grade_year/major/college 三字段保持原值 | 通过 |
| 15 | 管理员强制改校时同步修改 grade_year/major/college | 成功且审计留痕 |

---

## 9. 执行顺序与验收

| 步 | 动作 | 验收 |
|---|---|---|
| 1 | M1/M2 迁移 + C1 中间件（口径统一，legacy 全开） | 全量测试 388+N 通过；线上行为无可见变化 |
| 2 | C2/C3 strict 分支（无 strict 校区，分支不触发） | 测试 1~6 通过 |
| 3 | 写入侧盖章 + CAMPUS_NOT_BOUND（第 5 节） | 测试 4/7 通过 |
| 4 | 换校接口改造（第 7 节） | 测试 8~10 通过 |
| 5 | C4 命令 + 测试 11 | dry-run 报告评审通过 |
| 6 | 生产执行 P1：`campus:backfill --dry-run` → 评审报告 → 执行 → 校验清零 | 校验 SQL 全 0，rollback 映射落盘 |
| 7 | 发布 P0+P1（deploy-production 流程，迁移含在部署脚本 migrate 步骤） | release.json 更新 + 冒烟通过 |

> P2（选校门禁弹窗、`campus.bound` 路由组收口、试点校置 strict）依赖本清单交付的 `campus_required` 字段与 strict 分支，另行排期；本清单完成后即具备随时开 strict 的能力。

---

## 10. 风险与注意

- **ResolveCampusContext 重构**：先全文检索所有读取 `campus_database_id` attribute 的点（`Grep campus_database_id`），确保中间件合并后无遗漏；
- **缓存**：capabilities 有版本号机制（version=5→6），字段新增后小程序旧版不解析新字段无影响；
- **回填窗口**：P1 执行期间暂停运营建 NULL 任务/商品（或命令最后加二次扫描兜底）；
- **并行会话 WIP**：主工作区混入实名/审核等未提交改动，本次提交严格 scoped add，仅 add 本清单涉及文件；
- **新表不回滚**：与模块开关一致，回滚只切 `isolation_mode=legacy`，不 drop 表。
