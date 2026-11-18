<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Operations\Models\FeatureFlag;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class MaterialShareFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);

        FeatureFlag::query()->whereIn('code', ['module_material', 'module_material_post', 'module_material_request'])
            ->update(['mode' => 'enabled', 'rollout_percentage' => 100]);
    }

    public function test_contribution_review_and_quota_flow(): void
    {
        [$uploader, $uploaderToken] = $this->loginAndConsent('13800001001');
        [$viewer, $viewerToken] = $this->loginAndConsent('13800001002');
        [$admin, $adminToken] = $this->loginAdmin();

        // 投喂资料：智能解析链接与提取码，进入待审核
        $created = $this->withToken($uploaderToken)->postJson('/api/v1/materials', [
            'share_text' => '兽医执业 历年真题 https://pan.baidu.com/s/1AbCdEfGh 提取码：xk9d',
            'agreement_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.material.status', 'pending')
            ->assertJsonPath('data.parsed.extract_code', 'xk9d')
            ->assertJsonPath('data.parsed.netdisk_type', 'baidu');

        $materialId = $created->json('data.material.id');

        // 待审核资料不进入公开列表
        $this->getJson('/api/v1/materials')->assertOk()->assertJsonCount(0, 'data.items');

        // 管理员审核通过
        $this->withToken($adminToken)->getJson('/api/v1/admin/materials?status=pending')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$materialId.'/review', [
            'decision' => 'approve',
        ])->assertOk()->assertJsonPath('data.material.status', 'approved');

        // 公开列表可见
        unset($this->defaultHeaders['Authorization']);
        $this->getJson('/api/v1/materials')->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $materialId);

        // 投喂审核通过后赠送长期额度
        $this->withToken($uploaderToken)->getJson('/api/v1/materials/meta')
            ->assertOk()
            ->assertJsonPath('data.quota.bonus_quota', 10)
            ->assertJsonPath('data.quota.daily_limit', 1);

        // 其他用户获取资料：消耗一次每日额度并拿到网盘地址
        $this->withToken($viewerToken)->postJson('/api/v1/materials/'.$materialId.'/fetch')
            ->assertOk()
            ->assertJsonPath('data.share_url', 'https://pan.baidu.com/s/1AbCdEfGh')
            ->assertJsonPath('data.extract_code', 'xk9d')
            ->assertJsonPath('data.already_owned', false);

        // 重复获取同一资料不再扣额度
        $this->withToken($viewerToken)->postJson('/api/v1/materials/'.$materialId.'/fetch')
            ->assertOk()
            ->assertJsonPath('data.already_owned', true);

        // 每日额度用尽后拒绝获取
        config(['material.daily_limit' => 0]);
        [$third, $thirdToken] = $this->loginAndConsent('13800001003');
        $this->withToken($thirdToken)->postJson('/api/v1/materials/'.$materialId.'/fetch')
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATERIAL_QUOTA_EXHAUSTED');
    }

    public function test_material_request_is_visible_to_user_and_admin(): void
    {
        [$user, $token] = $this->loginAndConsent('13800002001');
        [$admin, $adminToken] = $this->loginAdmin();

        $this->withToken($token)->postJson('/api/v1/material-requests', [
            'title' => '高等数学期末历年真题',
            'requirement' => '需要近五年期末试卷，含答案解析。',
        ])->assertCreated()->assertJsonPath('data.request.status', 'pending');

        $this->withToken($token)->getJson('/api/v1/my-material-requests')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->withToken($adminToken)->getJson('/api/v1/admin/material-requests?status=pending')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'material-test-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data');
        foreach ($pending as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'material-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        $user = User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail();
        $this->bindTestCampus($user);

        return [$user, $token];
    }

    private function loginAdmin(): array
    {
        $admin = AdminUser::query()->create([
            'account' => 'material-admin-test',
            'display_name' => '资料测试管理员',
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $login = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'material-admin-test',
            'password' => 'StrongPassword123!',
        ])->assertOk();

        return [$admin, $login->json('data.access_token')];
    }
}
