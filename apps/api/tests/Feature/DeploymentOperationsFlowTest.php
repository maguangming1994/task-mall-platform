<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Operations\Models\DeploymentEvent;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Services\ReleaseControlService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class DeploymentOperationsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_feature_flag_rollout_is_versioned_audited_deterministic_and_optimistic(): void
    {
        $admin = AdminUser::query()->create(['account' => 'release-admin', 'display_name' => '发布管理员', 'password' => 'AdminPassword12345!', 'last_verified_at' => now()]);
        $flag = FeatureFlag::query()->where('code', 'checkout_v2')->firstOrFail();
        $service = app(ReleaseControlService::class);
        $updated = $service->updateFlag($admin, $flag, 'percentage', 25, ['platforms' => ['wechat-mini'], 'subject_allowlist' => ['vip-user']], 1, '先向微信小程序灰度百分之二十五。');

        $this->assertSame(2, $updated->version);
        $this->assertSame(25, $updated->rollout_percentage);
        $this->assertDatabaseHas('feature_flag_versions', ['feature_flag_id' => $flag->id, 'version' => 2, 'rollout_percentage' => 25]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'feature_flag.updated', 'subject_id' => 'checkout_v2']);
        $this->assertTrue($service->enabled('checkout_v2', 'vip-user', 'wechat-mini'));
        $this->assertFalse($service->enabled('checkout_v2', 'vip-user', 'browser-h5'));
        $this->assertSame($service->enabled('checkout_v2', 'stable-user', 'wechat-mini'), $service->enabled('checkout_v2', 'stable-user', 'wechat-mini'));

        try {
            $service->updateFlag($admin, $updated, 'enabled', 100, [], 1, '使用过期版本更新。');
            $this->fail('Expected optimistic lock failure.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('feature_flag_versions', 1);
        }
    }

    public function test_release_manifest_is_idempotent_and_canary_only_advances_before_append_only_rollback(): void
    {
        $service = app(ReleaseControlService::class);
        $commit = str_repeat('a', 40);
        $artifact = str_repeat('b', 64);
        $configuration = str_repeat('c', 64);
        $evidence = str_repeat('d', 64);
        $release = $service->registerRelease('v1.0.0', $commit, $artifact, $configuration);
        $same = $service->registerRelease('v1.0.0', $commit, $artifact, $configuration);
        $this->assertSame($release->public_id, $same->public_id);
        $this->assertSame('prepared', $release->status);
        $release = $service->transition($release, 'canary', 1, '启动百分之一灰度。', $evidence);
        $release = $service->transition($release, 'canary', 20, '指标稳定扩大到百分之二十。', $evidence);
        $this->assertSame('canary', $release->status);
        $this->assertSame(20, $release->rollout_percentage);
        try {
            $service->transition($release, 'canary', 10, '禁止灰度比例倒退。', $evidence);
            $this->fail('Expected rollout regression rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('deployment_events', 3);
        }
        $release = $service->transition($release, 'complete', 0, '全量发布门禁通过。', $evidence);
        $release = $service->transition($release, 'rollback', 0, '演练回滚并保留证据。', $evidence);
        $this->assertSame('rolled_back', $release->status);
        $this->assertSame(0, $release->rollout_percentage);
        $this->assertDatabaseCount('deployment_releases', 1);
        $this->assertDatabaseCount('deployment_events', 5);

        $this->expectException(LogicException::class);
        DeploymentEvent::query()->firstOrFail()->delete();
    }

    public function test_monitoring_metrics_are_token_protected_minimal_and_timed(): void
    {
        $token = str_repeat('m', 40);
        config(['monitoring.metrics_token' => $token]);
        $this->get('/api/v1/internal/metrics')->assertNotFound();
        $response = $this->withHeader('X-Monitoring-Token', $token)->get('/api/v1/internal/metrics')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Server-Timing');
        $body = $response->getContent();
        $this->assertStringContainsString('task_mall_up 1', $body);
        $this->assertStringContainsString('task_mall_ready 1', $body);
        $this->assertStringContainsString('task_mall_business_consistency 1', $body);
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('password', $body);
    }
}
