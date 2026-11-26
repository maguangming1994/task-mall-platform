<?php

namespace Tests\Feature;

use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCapabilitiesModuleGateTest extends TestCase
{
    use RefreshDatabase;

    private function baseHeaders(string $device, ?string $campusPublicId = null): array
    {
        $headers = [
            'X-Client-Platform' => 'wechat-mini',
            'X-Device-Id' => $device,
        ];
        if ($campusPublicId !== null) {
            $headers['X-Campus-Id'] = $campusPublicId;
        }

        return $headers;
    }

    public function test_payload_is_version_6_with_20_modules_and_experimental_flags(): void
    {
        $response = $this->getJson('/api/v1/client-capabilities', $this->baseHeaders('cap-device-1'));

        $response->assertOk()
            ->assertJsonPath('data.version', 6)
            ->assertJsonPath('data.campus_id', null)
            ->assertJsonPath('data.campus_required', false)
            ->assertJsonPath('data.campus_profile', null);

        $flags = $response->json('data.feature_flags');
        // 已上线模块全局默认 enabled；以下暂未上线能力默认 disabled，入口在客户端隐藏。
        $unlaunched = [
            'module_membership',
            'module_follow',
            'module_identity_verification',
            'module_feedback',
            'module_material',
            'module_material_post',
            'module_material_request',
        ];
        foreach (app(ModuleRegistry::class)->codes() as $code) {
            $this->assertArrayHasKey($code, $flags, "缺少模块开关 {$code}");
            $this->assertSame(
                ! in_array($code, $unlaunched, true),
                $flags[$code],
                "{$code} 的默认开启状态与预期不符",
            );
        }
        // 实验性 flag 仍然存在且走全局口径（播种为 disabled）
        $this->assertFalse($flags['checkout_v2']);
        $this->assertArrayHasKey('community_video', $flags);
        $this->assertArrayHasKey('external_notifications', $flags);

        // 旧字段完整
        $response->assertJsonStructure(['data' => [
            'login_methods', 'payment_methods', 'cash_payment_methods',
            'notification_channels', 'review_status', 'module_placeholders',
        ]]);
    }

    public function test_campus_overrides_change_module_flags_and_parent_chain(): void
    {
        FeatureFlag::query()->where('code', 'module_follow')->update(['mode' => 'enabled', 'rollout_percentage' => 100]);
        $mallOff = Campus::query()->create(['code' => 'cap-campus-a', 'name' => '关商城校', 'status' => 'active']);
        $communityOff = Campus::query()->create(['code' => 'cap-campus-b', 'name' => '关社区校', 'status' => 'active']);
        CampusFeatureFlag::query()->create(['campus_id' => $mallOff->id, 'code' => 'module_mall', 'status' => 'disabled']);
        CampusFeatureFlag::query()->create(['campus_id' => $communityOff->id, 'code' => 'module_community', 'status' => 'disabled']);

        $a = $this->getJson('/api/v1/client-capabilities', $this->baseHeaders('cap-device-2', $mallOff->public_id))
            ->assertOk()->assertJsonPath('data.campus_id', $mallOff->public_id)->json('data.feature_flags');
        $b = $this->getJson('/api/v1/client-capabilities', $this->baseHeaders('cap-device-2', $communityOff->public_id))
            ->assertOk()->assertJsonPath('data.campus_id', $communityOff->public_id)->json('data.feature_flags');

        // A 校：商城关，父链下交易/售后同步关；任务不受影响
        $this->assertFalse($a['module_mall']);
        $this->assertFalse($a['module_mall_trade']);
        $this->assertFalse($a['module_after_sale']);
        $this->assertTrue($a['module_tasks']);
        $this->assertTrue($a['module_community']);

        // B 校：社区关，子级关注即使全局开启也必须同步关闭；商城不受影响。
        $this->assertFalse($b['module_community']);
        $this->assertFalse($b['module_follow']);
        $this->assertTrue($b['module_mall']);
    }

    public function test_request_without_campus_header_follows_global_evaluation(): void
    {
        // 全局把邀请模块切成 disabled：无校区头应跟随全局关闭
        FeatureFlag::query()->where('code', 'module_referrals')->update(['mode' => 'disabled', 'rollout_percentage' => 0]);
        // 即便某校区显式开启，全局总闸门关闭时仍为关
        $campus = Campus::query()->create(['code' => 'cap-campus-c', 'name' => '显式开邀请校', 'status' => 'active']);
        CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_referrals', 'status' => 'enabled']);

        $noHeader = $this->getJson('/api/v1/client-capabilities', $this->baseHeaders('cap-device-3'))
            ->assertOk()->assertJsonPath('data.campus_id', null)->json('data.feature_flags');
        $this->assertFalse($noHeader['module_referrals']);

        $withHeader = $this->getJson('/api/v1/client-capabilities', $this->baseHeaders('cap-device-3', $campus->public_id))
            ->json('data.feature_flags');
        $this->assertFalse($withHeader['module_referrals']);

        // 无关模块仍跟随全局（开启）
        $this->assertTrue($noHeader['module_checkin']);
    }
}
