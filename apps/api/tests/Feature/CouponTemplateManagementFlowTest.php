<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTemplateManagementFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_coupon_template_create_new_version_status_and_budget_capacity_are_guarded_and_idempotent(): void
    {
        $token = $this->adminToken('coupon-operator', 'super_admin');
        $definition = [
            'name' => '任务组合券',
            'cash_discount_cents' => 500,
            'points_discount' => 100,
            'minimum_cash_cents' => 1000,
            'minimum_points' => 200,
            'valid_days' => 30,
            'total_quantity' => 100,
        ];
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-management-create-001'])
            ->postJson('/api/v1/admin/coupon-templates', $definition)
            ->assertCreated()->assertJsonPath('data.status', 'active');
        $templateId = $created->json('data.id');
        $this->withToken($token)->getJson('/api/v1/admin/coupon-templates')
            ->assertOk()->assertJsonCount(1, 'data.items');

        $versionHeaders = ['Idempotency-Key' => 'coupon-management-version-01'];
        $newDefinition = [
            ...$definition,
            'name' => '任务组合券 · 新版',
            'cash_discount_cents' => 600,
            'total_quantity' => 80,
            'reason' => '提高现金抵扣并保留旧模板绑定关系。',
        ];
        $newVersion = $this->withToken($token)->withHeaders($versionHeaders)
            ->postJson('/api/v1/admin/coupon-templates/'.$templateId.'/versions', $newDefinition)
            ->assertCreated()->assertJsonPath('data.status', 'inactive');
        $newTemplateId = $newVersion->json('data.id');
        $this->assertNotSame($templateId, $newTemplateId);
        $this->withToken($token)->withHeaders($versionHeaders)
            ->postJson('/api/v1/admin/coupon-templates/'.$templateId.'/versions', $newDefinition)
            ->assertCreated()->assertJsonPath('data.id', $newTemplateId);
        $this->assertDatabaseCount('coupon_templates', 2);

        $enabled = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-management-enable-01'])
            ->postJson('/api/v1/admin/coupon-templates/'.$newTemplateId.'/status', [
                'action' => 'enable', 'version' => 1, 'reason' => '新版本已完成运营复核。',
            ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-management-enable-01'])
            ->postJson('/api/v1/admin/coupon-templates/'.$newTemplateId.'/status', [
                'action' => 'enable', 'version' => 1, 'reason' => '重复启用请求。',
            ])->assertOk()->assertJsonPath('data.version', $enabled->json('data.version'));

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-capacity-pool-001'])
            ->postJson('/api/v1/admin/reward-budget-pools', [
                'name' => '新版券预算池', 'asset_type' => 'coupon', 'coupon_template_id' => $newTemplateId,
                'total_amount' => 80, 'reason' => '为任务优惠券奖励预留全部发行量。',
            ])->assertCreated()->assertJsonPath('data.coupon_template_id', $newTemplateId);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-capacity-pool-002'])
            ->postJson('/api/v1/admin/reward-budget-pools', [
                'name' => '超额券预算池', 'asset_type' => 'coupon', 'coupon_template_id' => $newTemplateId,
                'total_amount' => 1, 'reason' => '验证不可重复占用发行量。',
            ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertDatabaseHas('operation_logs', ['action' => 'coupon_template.create_version', 'subject_id' => $newTemplateId]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'coupon_template.enable', 'subject_id' => $newTemplateId]);
    }

    public function test_coupon_template_operations_require_product_permission_but_task_reward_options_allow_task_view(): void
    {
        $superToken = $this->adminToken('coupon-seeder', 'super_admin');
        $this->withToken($superToken)->withHeaders(['Idempotency-Key' => 'coupon-permission-create-01'])
            ->postJson('/api/v1/admin/coupon-templates', [
                'name' => '权限测试券', 'cash_discount_cents' => 100, 'points_discount' => 0,
                'minimum_cash_cents' => 0, 'minimum_points' => 0, 'valid_days' => 7, 'total_quantity' => 10,
            ])->assertCreated();

        $reviewerToken = $this->adminToken('coupon-task-reviewer', 'task_reviewer');
        $this->withToken($reviewerToken)->getJson('/api/v1/admin/coupon-templates')->assertForbidden();
        $this->withToken($reviewerToken)->getJson('/api/v1/admin/reward-coupon-templates')
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    private function adminToken(string $account, string $role): string
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }
}
