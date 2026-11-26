<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageDecorationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_empty_four_page_config_when_never_published(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);

        $this->getJson('/api/v1/page-decoration')
            ->assertOk()
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.version', null)
            ->assertJsonCount(0, 'data.pages.home.sections')
            ->assertJsonCount(0, 'data.pages.community.sections')
            ->assertJsonCount(0, 'data.pages.mall.sections')
            ->assertJsonCount(0, 'data.pages.account.sections');
    }

    public function test_draft_publish_and_rollback_flow_keeps_public_page_isolated_until_publish(): void
    {
        [, $token] = $this->admin('decoration-owner');

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithBanner(),
            'reason' => '首页新增欢迎轮播与富文本说明。',
        ])->assertOk()->assertJsonPath('data.review_status', 'not_required');

        $this->getJson('/api/v1/page-decoration')
            ->assertOk()
            ->assertJsonPath('data.version', null)
            ->assertJsonCount(0, 'data.pages.home.sections');

        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->getJson('/api/v1/page-decoration')
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(2, 'data.pages.home.sections')
            ->assertJsonPath('data.pages.home.sections.0.type', 'banner');

        $richText = (string) $this->getJson('/api/v1/page-decoration')->json('data.pages.home.sections.1.props.html');
        $this->assertStringContainsString('你好', $richText);
        $this->assertStringNotContainsString('onclick', $richText);
        $this->assertStringNotContainsString('<script', $richText);

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithAnnouncement(),
            'reason' => '临时改为公告条方案用于对比。',
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/page-decoration')
            ->assertJsonCount(1, 'data.pages.home.sections')
            ->assertJsonPath('data.pages.home.sections.0.type', 'announcement');

        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/rollback', [
            'target_version' => 1,
            'reason' => '公告条方案不符合预期，回滚到轮播方案。',
        ])->assertCreated()->assertJsonPath('data.rollback_source_version', 1);

        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.source', 'rollback');

        $this->getJson('/api/v1/page-decoration')
            ->assertJsonCount(2, 'data.pages.home.sections')
            ->assertJsonPath('data.pages.home.sections.0.type', 'banner');

        $this->withToken($token)->getJson('/api/v1/admin/page-decoration/versions')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
        $this->assertDatabaseHas('operation_logs', ['action' => 'page_decoration.publish']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'page_decoration.save_draft']);
    }

    public function test_invalid_sections_are_rejected_with_validation_errors(): void
    {
        [, $token] = $this->admin('decoration-validator');

        $unknownType = $this->valueWithBanner();
        $unknownType['pages']['home']['sections'][0]['type'] = 'unknown_block';
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $unknownType,
            'reason' => '验证未知区块类型会被拒绝。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $duplicateId = $this->valueWithBanner();
        $duplicateId['pages']['home']['sections'][1]['id'] = $duplicateId['pages']['home']['sections'][0]['id'];
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $duplicateId,
            'reason' => '验证重复区块标识会被拒绝。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $badUrl = $this->valueWithBanner();
        $badUrl['pages']['home']['sections'][0]['props']['items'][0]['image_url'] = 'javascript:alert(1)';
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $badUrl,
            'reason' => '验证不安全的图片地址会被拒绝。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_stale_lock_version_is_rejected(): void
    {
        [, $token] = $this->admin('decoration-lock');

        $first = $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithBanner(),
            'reason' => '第一次保存装修草稿。',
        ])->assertOk();
        $lockVersion = $first->json('data.lock_version');

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithAnnouncement(),
            'reason' => '第二次保存装修草稿。',
            'expected_lock_version' => $lockVersion,
        ])->assertOk();

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithAnnouncement(),
            'reason' => '使用过期锁版本保存草稿。',
            'expected_lock_version' => $lockVersion,
        ])->assertConflict()
            ->assertJsonPath('code', 'SETTING_DRAFT_CONFLICT')
            ->assertJsonPath('details.current_lock_version', $lockVersion + 1);
    }

    public function test_requires_settings_permission_and_recent_verification(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $noRole = AdminUser::query()->create([
            'account' => 'decoration-no-role',
            'display_name' => 'decoration-no-role',
            'password' => 'SettingAdminPassword123!',
        ]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $noRole->account,
            'password' => 'SettingAdminPassword123!',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/admin/page-decoration')->assertForbidden();

        [, $adminToken] = $this->admin('decoration-recent');
        AdminSession::query()->update(['verified_at' => now()->subDay()]);
        $this->withToken($adminToken)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $this->valueWithBanner(),
            'reason' => '验证近期核验缺失时无法保存。',
        ])->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');
    }

    public function test_generic_settings_endpoints_do_not_expose_decoration_key(): void
    {
        [, $token] = $this->admin('decoration-reader');

        $this->withToken($token)->getJson('/api/v1/admin/settings')
            ->assertOk();
        $keys = collect($this->withToken($token)->getJson('/api/v1/admin/settings')->json('data.items'))
            ->pluck('key')
            ->all();
        $this->assertNotContains('decoration.pages', $keys);

        $this->withToken($token)->getJson('/api/v1/admin/settings/decoration.pages')
            ->assertStatus(409)
            ->assertJsonPath('code', 'SETTING_SPECIALIZED_ENDPOINT');
    }

    /** @return array<string, mixed> */
    private function valueWithBanner(): array
    {
        return [
            'schema_version' => 1,
            'pages' => [
                'home' => ['background_url' => '', 'sections' => [
                    ['id' => 'home-banner', 'type' => 'banner', 'enabled' => true, 'props' => [
                        'items' => [[
                            'image_url' => 'https://example.com/banner.png',
                            'title' => '欢迎回来',
                            'subtitle' => '完成任务领取奖励',
                            'button_text' => '去任务大厅',
                            'link_type' => 'tasks',
                            'link_value' => '',
                        ]],
                        'autoplay' => true,
                        'interval_ms' => 4500,
                    ]],
                    ['id' => 'home-rich', 'type' => 'rich_text', 'enabled' => true, 'props' => [
                        'html' => '<p onclick="alert(1)"><strong>你好</strong><script>bad()</script></p>',
                    ]],
                ]],
                'community' => ['background_url' => '', 'sections' => []],
                'mall' => ['background_url' => '', 'sections' => []],
                'account' => ['background_url' => '', 'sections' => []],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function valueWithAnnouncement(): array
    {
        return [
            'schema_version' => 1,
            'pages' => [
                'home' => ['background_url' => '', 'sections' => [
                    ['id' => 'home-notice', 'type' => 'announcement', 'enabled' => true, 'props' => [
                        'icon_text' => '公',
                        'icon_url' => '',
                        'title' => '平台公告',
                        'content' => '本周任务奖励已更新。',
                        'link_type' => 'notifications',
                        'link_value' => '',
                    ]],
                ]],
                'community' => ['background_url' => '', 'sections' => []],
                'mall' => ['background_url' => '', 'sections' => []],
                'account' => ['background_url' => '', 'sections' => []],
            ],
        ];
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
