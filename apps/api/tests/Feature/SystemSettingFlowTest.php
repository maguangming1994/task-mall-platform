<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Settings\Models\SystemSettingVersion;
use App\Modules\Settings\Services\SystemSettingService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SystemSettingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_setting_supports_draft_publish_cache_refresh_versions_and_controlled_rollback(): void
    {
        [$admin, $token] = $this->admin('setting-owner');
        $key = 'tasks.default_submission_limit';

        $draft = $this->withToken($token)->putJson("/api/v1/admin/settings/{$key}/draft", [
            'value' => 5,
            'reason' => '任务试运行阶段需要增加提交机会。',
        ])->assertOk()
            ->assertJsonPath('data.review_status', 'not_required');

        $this->withToken($token)->postJson("/api/v1/admin/settings/{$key}/publish", [
            'confirm_publish' => true,
        ])->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.value', 5);

        $resolved = app(SystemSettingService::class)->read($key);
        $this->assertSame(5, $resolved['value']);
        $this->assertSame(1, $resolved['version']);

        $this->withToken($token)->putJson("/api/v1/admin/settings/{$key}/draft", [
            'value' => 6,
            'reason' => '根据首轮数据继续调整提交次数。',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/settings/{$key}/publish", [
            'confirm_publish' => true,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(6, app(SystemSettingService::class)->read($key)['value']);

        $rollback = $this->withToken($token)->postJson("/api/v1/admin/settings/{$key}/rollback", [
            'target_version' => 1,
            'reason' => '新参数效果不符合预期，回到已验证版本。',
        ])->assertCreated()
            ->assertJsonPath('data.rollback_source_version', 1);
        $this->withToken($token)->postJson("/api/v1/admin/settings/{$key}/publish", [
            'confirm_publish' => true,
        ])->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.source', 'rollback')
            ->assertJsonPath('data.value', 5);

        $this->withToken($token)->getJson("/api/v1/admin/settings/{$key}/versions")
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
        $this->assertDatabaseHas('operation_logs', ['action' => 'system_setting.publish']);
        $this->assertSame($admin->id, SystemSettingVersion::query()->latest('version')->value('published_by_admin_id'));
        $this->assertSame($draft->json('data.lock_version'), 1);
    }

    public function test_high_risk_setting_requires_a_different_authorized_reviewer(): void
    {
        [, $ownerToken] = $this->admin('setting-owner');
        [, $reviewerToken] = $this->admin('setting-reviewer');
        $key = 'wallet.withdraw_fee_rate';

        $this->withToken($ownerToken)->putJson("/api/v1/admin/settings/{$key}/draft", [
            'value' => 0.008,
            'reason' => '覆盖渠道成本变化并保留明确审计。',
        ])->assertOk()->assertJsonPath('data.review_status', 'pending');

        $this->withToken($ownerToken)->postJson("/api/v1/admin/settings/{$key}/review", [
            'decision' => 'approve',
            'comment' => '本人尝试审核自己的高风险配置。',
        ])->assertConflict()->assertJsonPath('code', 'SETTING_FOUR_EYES_REQUIRED');
        $this->withToken($ownerToken)->postJson("/api/v1/admin/settings/{$key}/publish", [
            'confirm_publish' => true,
        ])->assertConflict()->assertJsonPath('code', 'SETTING_REVIEW_REQUIRED');

        $this->withToken($reviewerToken)->postJson("/api/v1/admin/settings/{$key}/review", [
            'decision' => 'approve',
            'comment' => '费率范围和生效影响已经复核通过。',
        ])->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->withToken($ownerToken)->postJson("/api/v1/admin/settings/{$key}/publish", [
            'confirm_publish' => true,
        ])->assertOk()->assertJsonPath('data.value', 0.008);

        $version = SystemSettingVersion::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $version->update(['reason' => 'published versions cannot change']);
    }

    public function test_typed_validation_protected_invariants_and_optimistic_lock_are_enforced(): void
    {
        [, $token] = $this->admin('setting-validator');

        $this->withToken($token)->putJson('/api/v1/admin/settings/tasks.default_submission_limit/draft', [
            'value' => 'five',
            'reason' => '故意提交错误类型用于验证。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($token)->putJson('/api/v1/admin/settings/payment.shipping_cash_only/draft', [
            'value' => false,
            'reason' => '故意破坏运费仅现金约束用于验证。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $first = $this->withToken($token)->putJson('/api/v1/admin/settings/tasks.default_review_hours/draft', [
            'value' => 36,
            'reason' => '首次调整审核时间用于并发验证。',
        ])->assertOk();
        $lockVersion = $first->json('data.lock_version');
        $this->withToken($token)->putJson('/api/v1/admin/settings/tasks.default_review_hours/draft', [
            'value' => 40,
            'reason' => '第二次调整审核时间形成新锁版本。',
            'expected_lock_version' => $lockVersion,
        ])->assertOk();
        $this->withToken($token)->putJson('/api/v1/admin/settings/tasks.default_review_hours/draft', [
            'value' => 44,
            'reason' => '使用过期锁版本提交应当被拒绝。',
            'expected_lock_version' => $lockVersion,
        ])->assertConflict()
            ->assertJsonPath('code', 'SETTING_DRAFT_CONFLICT')
            ->assertJsonPath('details.current_lock_version', $lockVersion + 1);
    }

    public function test_setting_catalog_is_queryable_with_group_and_diff_metadata(): void
    {
        [, $token] = $this->admin('setting-reader');
        $this->withToken($token)->getJson('/api/v1/admin/settings?group=wallet')
            ->assertOk()
            ->assertJsonPath('data.groups.0', 'wallet')
            ->assertJsonCount(6, 'data.items')
            ->assertJsonPath('data.items.0.current_source', 'default');
    }

    public function test_bottom_navigation_rejects_unsafe_targets_and_too_few_visible_items(): void
    {
        [, $token] = $this->admin('navigation-validator');
        $items = [
            ['id' => 'home', 'label' => '首页', 'target' => 'home', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
            ['id' => 'tasks', 'label' => '任务', 'target' => 'tasks', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
            ['id' => 'products', 'label' => '商城', 'target' => 'products', 'enabled' => false, 'icon_url' => '', 'active_icon_url' => ''],
            ['id' => 'community', 'label' => '社区', 'target' => 'community', 'enabled' => false, 'icon_url' => '', 'active_icon_url' => ''],
            ['id' => 'account', 'label' => '我的', 'target' => 'https://example.com', 'enabled' => false, 'icon_url' => '', 'active_icon_url' => ''],
        ];

        $this->withToken($token)->putJson('/api/v1/admin/settings/navigation.bottom_tabs/draft', [
            'value' => [
                'items' => $items,
                'style' => ['background_color' => '#FFFFFF', 'text_color' => '#7A859B', 'active_color' => '#2F67DF', 'border_color' => '#E7EBF2'],
            ],
            'reason' => 'Validate navigation target whitelist and minimum visible entries.',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $items[4]['target'] = 'account';
        $this->withToken($token)->putJson('/api/v1/admin/settings/navigation.bottom_tabs/draft', [
            'value' => [
                'items' => $items,
                'style' => ['background_color' => '#FFFFFF', 'text_color' => '#7A859B', 'active_color' => '#2F67DF', 'border_color' => '#E7EBF2'],
            ],
            'reason' => 'Validate minimum visible navigation entries after target correction.',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_home_announcement_accepts_safe_rich_text_and_removes_unsafe_markup(): void
    {
        [, $token] = $this->admin('announcement-editor');

        $response = $this->withToken($token)->putJson('/api/v1/admin/settings/homepage.announcement/draft', [
            'value' => [
                'enabled' => true,
                'icon_text' => '公',
                'title' => '平台公告',
                'content' => '<p onclick="alert(1)"><strong>重要通知</strong><script>alert(2)</script></p>',
                'link_type' => 'notifications',
            ],
            'reason' => 'Enable safe rich text formatting for the home announcement.',
        ])->assertOk();

        $content = (string) $response->json('data.value.content');
        $this->assertStringContainsString('<strong>重要通知</strong>', $content);
        $this->assertStringNotContainsString('onclick', $content);
        $this->assertStringNotContainsString('<script', $content);
    }

    /** @return array{AdminUser, string} */
    private function admin(string $account): array
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'SettingAdminPassword123!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'SettingAdminPassword123!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
