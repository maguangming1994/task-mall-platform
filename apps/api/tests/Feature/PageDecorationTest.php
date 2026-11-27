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
            ->assertJsonPath('data.pages.home.sections.0.type', 'banner')
            ->assertJsonPath('data.pages.home.sections.0.style.channels', ['h5', 'miniapp'])
            ->assertJsonPath('data.pages.home.sections.0.style.display_start', '')
            ->assertJsonPath('data.pages.home.name', '');
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

        $badChannel = $this->valueWithBanner();
        $badChannel['pages']['home']['sections'][0]['style'] = ['channels' => ['app']];
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $badChannel,
            'reason' => '验证不支持的显示渠道会被拒绝。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $badSpacing = $this->valueWithBanner();
        $badSpacing['pages']['home']['sections'][0]['style'] = ['margin' => ['top' => 500]];
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $badSpacing,
            'reason' => '验证超出范围的外边距会被拒绝。',
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

    public function test_advanced_style_and_display_settings_are_normalized(): void
    {
        [, $token] = $this->admin('decoration-style-owner');

        $value = [
            'schema_version' => 1,
            'pages' => [
                'home' => ['sections' => [
                    [
                        'id' => 'home-banner',
                        'type' => 'banner',
                        'enabled' => true,
                        'style' => [
                            'corner_radius' => true,
                            'radius_corners' => ['top_left' => 12, 'top_right' => 0, 'bottom_left' => 0, 'bottom_right' => 24],
                            'opacity' => 60,
                            'display_daily_start' => '09:00',
                            'display_daily_end' => '22:30',
                        ],
                        'props' => [
                            'items' => [[
                                'image_url' => 'https://example.com/a.png',
                                'title' => 'A',
                                'subtitle' => '',
                                'button_text' => '查看',
                                'link_type' => 'none',
                                'link_value' => '',
                            ]],
                            'height_rpx' => 360,
                            'indicator' => 'numbers',
                            'indicator_position' => 'top-center',
                            'show_title' => false,
                        ],
                    ],
                    [
                        'id' => 'home-grid',
                        'type' => 'quick_grid',
                        'enabled' => true,
                        'props' => [
                            'columns' => 5,
                            'icon_shape' => 'circle',
                            'icon_size_rpx' => 96,
                            'label_color' => '#123456',
                            'items' => [[
                                'id' => 'e1',
                                'label' => '任务',
                                'mark' => '任',
                                'icon_url' => '',
                                'link_type' => 'tasks',
                                'enabled' => true,
                                'badge_type' => 'text',
                                'badge_text' => '新',
                            ]],
                        ],
                    ],
                    ['id' => 'home-products', 'type' => 'product_grid', 'enabled' => true, 'props' => [
                        'columns' => 3,
                        'show_price' => false,
                        'show_subtitle' => true,
                        'show_category' => true,
                    ]],
                    ['id' => 'home-notice', 'type' => 'notice_bar', 'enabled' => true, 'props' => [
                        'tone' => 'normal',
                        'show_icon' => false,
                        'title_size_rpx' => 32,
                    ]],
                ]],
                'community' => ['sections' => []],
                'mall' => ['sections' => []],
                'account' => ['sections' => []],
            ],
        ];

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $value,
            'reason' => '补充通用样式与组件展示参数。',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk();

        $config = $this->getJson('/api/v1/page-decoration')->assertOk()->json('data');
        $banner = $config['pages']['home']['sections'][0];
        $this->assertSame(12, $banner['style']['radius_corners']['top_left']);
        $this->assertSame(24, $banner['style']['radius_corners']['bottom_right']);
        $this->assertSame(60, $banner['style']['opacity']);
        $this->assertSame('09:00', $banner['style']['display_daily_start']);
        $this->assertSame('22:30', $banner['style']['display_daily_end']);
        $this->assertSame(360, $banner['props']['height_rpx']);
        $this->assertSame('numbers', $banner['props']['indicator']);
        $this->assertFalse($banner['props']['show_title']);

        $grid = $config['pages']['home']['sections'][1];
        $this->assertSame('circle', $grid['props']['icon_shape']);
        $this->assertSame('#123456', $grid['props']['label_color']);
        $this->assertSame('text', $grid['props']['items'][0]['badge_type']);
        $this->assertSame('新', $grid['props']['items'][0]['badge_text']);

        $products = $config['pages']['home']['sections'][2];
        $this->assertSame(3, $products['props']['columns']);
        $this->assertTrue($products['props']['show_category']);
        $this->assertFalse($products['props']['show_price']);

        $notice = $config['pages']['home']['sections'][3];
        $this->assertSame('normal', $notice['props']['tone']);
        $this->assertFalse($notice['props']['show_icon']);
    }

    public function test_advanced_style_rejects_out_of_range_values(): void
    {
        [, $token] = $this->admin('decoration-style-guard');

        $value = [
            'schema_version' => 1,
            'pages' => [
                'home' => ['sections' => [
                    ['id' => 'home-banner', 'type' => 'banner', 'enabled' => true, 'style' => ['opacity' => 10], 'props' => ['items' => []]],
                ]],
                'community' => ['sections' => []],
                'mall' => ['sections' => []],
                'account' => ['sections' => []],
            ],
        ];

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $value,
            'reason' => '校验透明度边界。',
        ])->assertStatus(422);

        $value['pages']['home']['sections'][0]['style'] = ['display_daily_start' => '9:00'];
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $value,
            'reason' => '校验每日时段格式。',
        ])->assertStatus(422);
    }

    public function test_tile_ad_popup_and_data_source_sections_are_supported(): void
    {
        [, $token] = $this->admin('decoration-next-owner');

        $value = [
            'schema_version' => 1,
            'pages' => [
                'home' => ['sections' => [
                    [
                        'id' => 'home-tiles',
                        'type' => 'tile_ad',
                        'enabled' => true,
                        'props' => [
                            'layout' => 'big-right',
                            'radius_rpx' => 16,
                            'gap_rpx' => 10,
                            'tag_bg' => '#123456',
                            'items' => [
                                ['image_url' => 'https://example.com/1.png', 'title' => '主推', 'subtitle' => '副标题', 'tag' => '推荐', 'link_type' => 'tasks', 'link_value' => ''],
                                ['image_url' => 'https://example.com/2.png', 'title' => '二', 'subtitle' => '', 'tag' => '', 'link_type' => 'products', 'link_value' => ''],
                                ['image_url' => 'https://example.com/3.png', 'title' => '三', 'subtitle' => '', 'tag' => '', 'link_type' => 'community', 'link_value' => ''],
                            ],
                        ],
                    ],
                    [
                        'id' => 'home-popup',
                        'type' => 'popup',
                        'enabled' => true,
                        'props' => [
                            'image_url' => 'https://example.com/popup.png',
                            'title' => '新人礼包',
                            'content' => '完成任务领取积分',
                            'button_text' => '立即参与',
                            'link_type' => 'tasks',
                            'frequency' => 'once',
                            'closable' => false,
                            'width_rpx' => 600,
                        ],
                    ],
                    [
                        'id' => 'home-announce',
                        'type' => 'announcement',
                        'enabled' => true,
                        'props' => ['title' => '公告', 'data_source' => 'system', 'limit' => 5],
                    ],
                    [
                        'id' => 'home-grid',
                        'type' => 'quick_grid',
                        'enabled' => true,
                        'props' => ['columns' => 4, 'data_source' => 'product_category', 'limit' => 6, 'items' => []],
                    ],
                    [
                        'id' => 'home-platform-banner',
                        'type' => 'banner',
                        'enabled' => true,
                        'props' => ['items' => [], 'data_source' => 'platform', 'limit' => 4],
                    ],
                ]],
                'community' => ['sections' => []],
                'mall' => ['sections' => []],
                'account' => ['sections' => []],
            ],
        ];

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $value,
            'reason' => '补充瓷片广告、启动弹窗与数据源能力。',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk();

        $config = $this->getJson('/api/v1/page-decoration')->assertOk()->json('data');
        $tiles = $config['pages']['home']['sections'][0];
        $this->assertSame('tile_ad', $tiles['type']);
        $this->assertSame('big-right', $tiles['props']['layout']);
        $this->assertSame(16, $tiles['props']['radius_rpx']);
        $this->assertSame('#123456', $tiles['props']['tag_bg']);
        $this->assertCount(3, $tiles['props']['items']);
        $this->assertSame('推荐', $tiles['props']['items'][0]['tag']);

        $popup = $config['pages']['home']['sections'][1];
        $this->assertSame('once', $popup['props']['frequency']);
        $this->assertFalse($popup['props']['closable']);
        $this->assertSame(600, $popup['props']['width_rpx']);

        $announce = $config['pages']['home']['sections'][2];
        $this->assertSame('system', $announce['props']['data_source']);
        $this->assertSame(5, $announce['props']['limit']);

        $grid = $config['pages']['home']['sections'][3];
        $this->assertSame('product_category', $grid['props']['data_source']);
        $this->assertSame(6, $grid['props']['limit']);

        $platformBanner = $config['pages']['home']['sections'][4];
        $this->assertSame('platform', $platformBanner['props']['data_source']);
        $this->assertSame(4, $platformBanner['props']['limit']);
    }

    public function test_tile_ad_rejects_more_than_three_tiles(): void
    {
        [, $token] = $this->admin('decoration-tile-guard');

        $value = [
            'schema_version' => 1,
            'pages' => [
                'home' => ['sections' => [[
                    'id' => 'home-tiles',
                    'type' => 'tile_ad',
                    'enabled' => true,
                    'props' => ['items' => array_fill(0, 4, ['image_url' => '', 'title' => '瓷片', 'subtitle' => '', 'tag' => '', 'link_type' => 'none', 'link_value' => ''])],
                ]]],
                'community' => ['sections' => []],
                'mall' => ['sections' => []],
                'account' => ['sections' => []],
            ],
        ];

        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => $value,
            'reason' => '校验瓷片数量上限。',
        ])->assertStatus(422);
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
