<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\ExpressPickup\Services\ReceiptTemplateService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecorationSchemeAndReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheme_overview_derives_defaults_and_activation_mirrors_live_pages(): void
    {
        [, $token] = $this->admin('scheme-owner');
        $this->publishLiveBanner($token);

        $overview = $this->withToken($token)->getJson('/api/v1/admin/decoration/schemes')
            ->assertOk()
            ->json('data');
        $this->assertCount(4, $overview['schemes']);
        $this->assertSame('default-home', $overview['pages']['home']['h5']);
        $this->assertTrue($overview['schemes'][0]['derived']);

        $schemeId = $this->withToken($token)->postJson('/api/v1/admin/decoration/schemes', [
            'name' => '国庆活动首页',
            'page_key' => 'home',
        ])->assertCreated()->json('data.scheme.id');

        $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$schemeId}", [
            'name' => '国庆活动首页',
            'page' => $this->richTextPage('国庆快乐'),
            'reason' => '保存国庆活动方案。',
        ])->assertOk();

        // 方案保存不影响线上，只有启用公众号渠道后才镜像到页面装修。
        $this->getJson('/api/v1/page-decoration')
            ->assertOk()
            ->assertJsonPath('data.pages.home.sections.0.type', 'banner');

        $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$schemeId}/channel", [
            'channel' => 'h5',
            'enabled' => true,
        ])->assertOk()
            ->assertJsonPath("data.pages.home.h5", $schemeId);

        $live = $this->getJson('/api/v1/page-decoration')->assertOk()->json('data');
        $this->assertSame('rich_text', $live['pages']['home']['sections'][0]['type']);
        $this->assertStringContainsString('国庆快乐', (string) $live['pages']['home']['sections'][0]['props']['html']);
        $this->assertCount(1, $live['pages']['home']['sections']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'page_scheme.channel']);
    }

    public function test_scheme_channel_is_exclusive_and_active_scheme_cannot_be_deleted(): void
    {
        [, $token] = $this->admin('scheme-guard');
        $first = $this->createHomeScheme($token, '方案一');
        $second = $this->createHomeScheme($token, '方案二');

        $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$first}/channel", [
            'channel' => 'h5',
            'enabled' => true,
        ])->assertOk();
        $overview = $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$second}/channel", [
            'channel' => 'h5',
            'enabled' => true,
        ])->assertOk()->json('data');
        $this->assertSame($second, $overview['pages']['home']['h5']);

        // 小程序渠道只记录指针，不覆盖线上页面。
        $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$first}/channel", [
            'channel' => 'miniapp',
            'enabled' => true,
        ])->assertOk()->assertJsonPath('data.pages.home.miniapp', $first);
        $this->getJson('/api/v1/page-decoration')->assertOk()->assertJsonPath('data.version', 2);

        $this->withToken($token)->deleteJson("/api/v1/admin/decoration/schemes/{$second}")
            ->assertStatus(409);

        $this->withToken($token)->putJson("/api/v1/admin/decoration/schemes/{$second}/channel", [
            'channel' => 'h5',
            'enabled' => false,
        ])->assertOk()->assertJsonPath('data.pages.home.h5', null);
        $this->withToken($token)->deleteJson("/api/v1/admin/decoration/schemes/{$second}")
            ->assertOk();
    }

    public function test_receipt_template_binding_compiles_and_disable_falls_back(): void
    {
        [, $token] = $this->admin('receipt-owner');
        $templateId = $this->withToken($token)->postJson('/api/v1/admin/decoration/receipt-templates', [
            'name' => '58mm 快递小票',
            'paper_width' => 58,
            'print_type' => 'express_pickup',
            'status' => 'enabled',
            'blocks' => $this->receiptBlocks(),
        ])->assertCreated()->json('data.template.id');

        $this->withToken($token)->putJson('/api/v1/admin/decoration/receipt-templates/bindings', [
            'bindings' => ['express_pickup' => ['receipt' => $templateId]],
        ])->assertOk()
            ->assertJsonPath('data.bindings.express_pickup.receipt', $templateId)
            ->assertJsonPath('data.bindings.delivery.receipt', null);

        /** @var ReceiptTemplateService $service */
        $service = app(ReceiptTemplateService::class);
        $compiled = $service->compiledForScene('express_pickup', [
            'order_no' => 'EP123',
            'pickup_code' => '8-2-3021',
        ]);
        $this->assertNotNull($compiled);
        $this->assertStringContainsString('<C><B>快递代取</B></C>', $compiled);
        $this->assertStringContainsString('取件码：8-2-3021', $compiled);
        $this->assertStringContainsString(str_repeat('-', 32), $compiled);
        $this->assertStringContainsString('<C><QR>EP123</QR></C>', $compiled);
        $this->assertNull($service->compiledForScene('delivery', ['order_no' => 'EP123']));

        $preview = $this->withToken($token)->postJson('/api/v1/admin/decoration/receipt-templates/preview', [
            'paper_width' => 58,
            'blocks' => $this->receiptBlocks(),
        ])->assertOk()->json('data');
        $this->assertStringContainsString('<C><B>快递代取</B></C>', $preview['content']);
        $this->assertStringContainsString('快递代取', $preview['preview']);
        $this->assertStringNotContainsString('<C>', $preview['preview']);
        $this->assertStringContainsString('取件码：8-2-3021', $preview['preview']);

        // 停用后回落到小票机自带模板，编译结果为空。
        $this->withToken($token)->postJson("/api/v1/admin/decoration/receipt-templates/{$templateId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.template.status', 'disabled');
        $this->assertNull($service->compiledForScene('express_pickup', ['order_no' => 'EP123']));

        // 删除模板时自动清理绑定。
        $this->withToken($token)->deleteJson("/api/v1/admin/decoration/receipt-templates/{$templateId}")
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/decoration/receipt-templates')
            ->assertOk()
            ->assertJsonPath('data.bindings.express_pickup.receipt', null)
            ->assertJsonCount(0, 'data.templates');
    }

    public function test_specialized_decoration_keys_stay_out_of_generic_settings(): void
    {
        [, $token] = $this->admin('scheme-settings-guard');
        $response = $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk();
        $keys = collect($response->json('data.items'))->pluck('setting_key');
        $this->assertFalse($keys->contains('decoration.schemes'));
        $this->assertFalse($keys->contains('decoration.receipt_templates'));
        $this->assertFalse($keys->contains('decoration.pages'));
    }

    private function publishLiveBanner(string $token): void
    {
        $this->withToken($token)->putJson('/api/v1/admin/page-decoration/draft', [
            'value' => [
                'schema_version' => 1,
                'pages' => [
                    'home' => ['sections' => [[
                        'id' => 'home-banner',
                        'type' => 'banner',
                        'enabled' => true,
                        'props' => ['items' => []],
                    ]]],
                    'community' => ['sections' => []],
                    'mall' => ['sections' => []],
                    'account' => ['sections' => []],
                ],
            ],
            'reason' => '准备线上默认页面内容。',
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/page-decoration/publish', [
            'confirm_publish' => true,
        ])->assertOk();
    }

    private function createHomeScheme(string $token, string $name): string
    {
        return $this->withToken($token)->postJson('/api/v1/admin/decoration/schemes', [
            'name' => $name,
            'page_key' => 'home',
        ])->assertCreated()->json('data.scheme.id');
    }

    /** @return array<string, mixed> */
    private function richTextPage(string $text): array
    {
        return [
            'name' => '',
            'title' => '',
            'description' => '',
            'cover_url' => '',
            'background_color' => '',
            'background_url' => '',
            'sections' => [[
                'id' => 'home-rich',
                'type' => 'rich_text',
                'enabled' => true,
                'props' => ['html' => '<p>'.$text.'</p>'],
            ]],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function receiptBlocks(): array
    {
        return [
            ['id' => 'blk-title', 'type' => 'text', 'props' => ['content' => '快递代取', 'align' => 'center', 'font_size' => 1, 'bold' => 1]],
            ['id' => 'blk-code', 'type' => 'text', 'props' => ['content' => '取件码：${pickup_code}', 'align' => 'left', 'font_size' => 0, 'bold' => 0]],
            ['id' => 'blk-divider', 'type' => 'divider', 'props' => ['content' => '', 'align' => 'left', 'font_size' => 0, 'bold' => 0]],
            ['id' => 'blk-qr', 'type' => 'qr_code', 'props' => ['content' => '${order_no}', 'align' => 'center', 'font_size' => 0, 'bold' => 0]],
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
