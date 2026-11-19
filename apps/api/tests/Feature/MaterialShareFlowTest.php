<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialReport;
use App\Modules\Notification\Models\Notification;
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

    public function test_duplicate_share_url_submission_is_rejected(): void
    {
        [$uploader, $token] = $this->loginAndConsent('13800003001');

        $payload = [
            'share_text' => '高等数学 期末真题 https://pan.baidu.com/s/1SameLink001 提取码：ab12',
            'agreement_accepted' => true,
        ];

        $this->withToken($token)->postJson('/api/v1/materials', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/materials', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATERIAL_DUPLICATE_SUBMISSION');
    }

    public function test_same_share_url_is_rewarded_only_once(): void
    {
        [$uploader, $token] = $this->loginAndConsent('13800003002');
        [$admin, $adminToken] = $this->loginAdmin();

        $base = [
            'campus_id' => $uploader->campus_id,
            'uploader_user_id' => $uploader->id,
            'uploader_name' => $uploader->nickname,
            'title' => '重复链接资料',
            'netdisk_type' => 'baidu',
            'share_url' => 'https://pan.baidu.com/s/1SameLink002',
            'share_text' => 'https://pan.baidu.com/s/1SameLink002 提取码：cd34',
            'file_type' => 'pdf',
            'source' => 'user',
            'status' => 'pending',
        ];
        $first = Material::query()->create($base);
        $second = Material::query()->create($base);

        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$first->public_id.'/review', ['decision' => 'approve'])->assertOk();
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$second->public_id.'/review', ['decision' => 'approve'])->assertOk();

        // 同一用户同一网盘链接只奖励一次
        $this->withToken($token)->getJson('/api/v1/materials/meta')
            ->assertOk()->assertJsonPath('data.quota.bonus_quota', 10);

        // 已通过资料再次审核（重复点击）不追加奖励
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$first->public_id.'/review', ['decision' => 'approve'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/materials/meta')
            ->assertOk()->assertJsonPath('data.quota.bonus_quota', 10);
    }

    public function test_material_review_sends_result_notification(): void
    {
        [$uploader, $uploaderToken] = $this->loginAndConsent('13800006001');
        [$admin, $adminToken] = $this->loginAdmin();

        // 投稿 A：审核通过 → 作者收到通过通知
        $createdA = $this->withToken($uploaderToken)->postJson('/api/v1/materials', [
            'share_text' => '审核通知资料A https://pan.baidu.com/s/1ReviewNotifyA 提取码：na01',
            'agreement_accepted' => true,
        ])->assertCreated();
        $idA = $createdA->json('data.material.id');

        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$idA.'/review', ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.material.status', 'approved');
        $this->assertSame(1, Notification::query()->where('user_id', $uploader->id)->where('scene_code', 'material_review_approved')->count());

        // 幂等：重复审核通过不重复发通知
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$idA.'/review', ['decision' => 'approve'])->assertOk();
        $this->assertSame(1, Notification::query()->where('user_id', $uploader->id)->where('scene_code', 'material_review_approved')->count());

        // 下架已通过资料 → 作者收到下架通知（复用 material_takedown 场景，eventKey 独立于举报下架）
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$idA.'/review', [
            'decision' => 'offline',
            'reason' => '内容需要调整后再上架',
        ])->assertOk()->assertJsonPath('data.material.status', 'offline');
        $this->assertSame(1, Notification::query()->where('user_id', $uploader->id)->where('scene_code', 'material_takedown')->count());

        // 投稿 B：驳回 → 作者收到驳回通知，正文包含原因
        $createdB = $this->withToken($uploaderToken)->postJson('/api/v1/materials', [
            'share_text' => '审核通知资料B https://pan.baidu.com/s/1ReviewNotifyB 提取码：nb01',
            'agreement_accepted' => true,
        ])->assertCreated();
        $idB = $createdB->json('data.material.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$idB.'/review', [
            'decision' => 'reject',
            'reason' => '链接无效，请核实后重新分享',
        ])->assertOk()->assertJsonPath('data.material.status', 'rejected');
        $rejected = Notification::query()->where('user_id', $uploader->id)->where('scene_code', 'material_review_rejected')->first();
        $this->assertNotNull($rejected);
        $this->assertStringContainsString('链接无效，请核实后重新分享', $rejected->body);

        // 平台补录资料无投稿人：审核不产生任何通知
        $before = Notification::query()->count();
        $adminCreated = $this->withToken($adminToken)->postJson('/api/v1/admin/materials', [
            'title' => '平台补录无作者',
            'share_url' => 'https://pan.baidu.com/s/1AdminNoAuthor',
            'extract_code' => 'aa00',
            'netdisk_type' => 'baidu',
            'file_type' => 'pdf',
        ])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$adminCreated->json('data.material.id').'/review', ['decision' => 'approve'])->assertOk();
        $this->assertSame($before, Notification::query()->count());
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

    public function test_material_request_reply_sends_notification(): void
    {
        [$user, $token] = $this->loginAndConsent('13800002002');
        [$admin, $adminToken] = $this->loginAdmin();

        $created = $this->withToken($token)->postJson('/api/v1/material-requests', [
            'title' => '大学物理实验指导书',
            'requirement' => '需要含完整实验步骤和数据处理范例的版本。',
        ])->assertCreated();
        $requestId = $created->json('data.request.id');

        $count = fn () => Notification::query()->where('user_id', $user->id)->where('scene_code', 'material_request_replied')->count();

        // 初始无通知
        $this->assertSame(0, $count());

        // 管理员回复：pending -> processing，带备注 → 通知发起人
        $this->withToken($adminToken)->putJson('/api/v1/admin/material-requests/'.$requestId, [
            'status' => 'processing',
            'admin_note' => '已在资料库中查找，预计 1-2 天内反馈。',
        ])->assertOk();
        $this->assertSame(1, $count());

        // 重复提交完全相同内容 → 不重复通知（幂等）
        $this->withToken($adminToken)->putJson('/api/v1/admin/material-requests/'.$requestId, [
            'status' => 'processing',
            'admin_note' => '已在资料库中查找，预计 1-2 天内反馈。',
        ])->assertOk();
        $this->assertSame(1, $count());

        // 状态变更 -> done，带新备注 → 再通知一条
        $this->withToken($adminToken)->putJson('/api/v1/admin/material-requests/'.$requestId, [
            'status' => 'done',
            'admin_note' => '已找到：链接 xxx 提取码 yyy。',
        ])->assertOk();
        $this->assertSame(2, $count());

        // 最新通知正文包含状态与回复内容
        $latest = Notification::query()->where('user_id', $user->id)->where('scene_code', 'material_request_replied')->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertStringContainsString('已完成', $latest->body);
        $this->assertStringContainsString('已找到', $latest->body);

        // 仅改备注（状态不变）也应通知一次：每次新回复都送达
        $this->withToken($adminToken)->putJson('/api/v1/admin/material-requests/'.$requestId, [
            'status' => 'done',
            'admin_note' => '补充：资料已上传至平台，可在资料列表直接获取。',
        ])->assertOk();
        $this->assertSame(3, $count());
    }

    public function test_admin_can_create_and_update_material(): void
    {
        [$admin, $adminToken] = $this->loginAdmin();

        // 补录：粘贴分享文案自动解析，默认直接上架，平台级对所有校区可见
        $created = $this->withToken($adminToken)->postJson('/api/v1/admin/materials', [
            'title' => '平台补录：线性代数习题集',
            'share_text' => '线性代数习题 https://pan.quark.cn/s/1AdminEntry 提取码：xyz7',
        ])->assertCreated()
            ->assertJsonPath('data.material.status', 'approved')
            ->assertJsonPath('data.material.source', 'admin')
            ->assertJsonPath('data.material.netdisk_type', 'quark')
            ->assertJsonPath('data.material.extract_code', 'xyz7');

        $materialId = $created->json('data.material.id');

        unset($this->defaultHeaders['Authorization']);
        $this->getJson('/api/v1/materials')->assertOk()
            ->assertJsonPath('data.items.0.id', $materialId)
            ->assertJsonPath('data.items.0.title', '平台补录：线性代数习题集');

        // 编辑：修正标题与链接
        $this->withToken($adminToken)->putJson('/api/v1/admin/materials/'.$materialId, [
            'title' => '平台补录：线性代数习题集（修订）',
            'share_url' => 'https://pan.quark.cn/s/1AdminEdited',
            'extract_code' => 'new1',
            'netdisk_type' => 'quark',
            'file_type' => 'pdf',
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('data.material.title', '平台补录：线性代数习题集（修订）');

        unset($this->defaultHeaders['Authorization']);
        $this->getJson('/api/v1/materials')->assertOk()
            ->assertJsonPath('data.items.0.title', '平台补录：线性代数习题集（修订）');
    }

    public function test_my_fetches_lists_acquired_materials(): void
    {
        [$viewer, $viewerToken] = $this->loginAndConsent('13800004001');
        [$admin, $adminToken] = $this->loginAdmin();

        $created = $this->withToken($adminToken)->postJson('/api/v1/admin/materials', [
            'title' => '获取记录测试资料',
            'share_url' => 'https://pan.baidu.com/s/1MyFetchTest',
            'extract_code' => 'ft01',
            'netdisk_type' => 'baidu',
            'file_type' => 'pdf',
        ])->assertCreated();
        $materialId = $created->json('data.material.id');

        $this->withToken($viewerToken)->postJson('/api/v1/materials/'.$materialId.'/fetch')
            ->assertOk()
            ->assertJsonPath('data.share_url', 'https://pan.baidu.com/s/1MyFetchTest');

        $this->withToken($viewerToken)->getJson('/api/v1/my-material-fetches')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.material.id', $materialId)
            ->assertJsonPath('data.items.0.material.share_url', 'https://pan.baidu.com/s/1MyFetchTest')
            ->assertJsonPath('data.items.0.material.extract_code', 'ft01');
    }

    public function test_report_handling_closed_loop(): void
    {
        [$uploader, $uploaderToken] = $this->loginAndConsent('13800005001');
        [$reporterA, $reporterAToken] = $this->loginAndConsent('13800005002');
        [$reporterB, $reporterBToken] = $this->loginAndConsent('13800005003');
        [$admin, $adminToken] = $this->loginAdmin();

        // 投稿并审核通过
        $created = $this->withToken($uploaderToken)->postJson('/api/v1/materials', [
            'share_text' => '举报闭环测试 https://pan.baidu.com/s/1ReportLoop 提取码：rp01',
            'agreement_accepted' => true,
        ])->assertCreated();
        $materialId = $created->json('data.material.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/materials/'.$materialId.'/review', ['decision' => 'approve'])->assertOk();

        // 两名用户分别举报同一资料
        $this->withToken($reporterAToken)->postJson('/api/v1/materials/'.$materialId.'/reports', ['reason' => '内容侵权'])->assertOk();
        $this->withToken($reporterBToken)->postJson('/api/v1/materials/'.$materialId.'/reports', ['reason' => '涉嫌盗版'])->assertOk();

        $pending = $this->withToken($adminToken)->getJson('/api/v1/admin/material-reports?status=pending')
            ->assertOk()->assertJsonPath('data.pagination.total', 2)
            ->json('data.items');
        $reportId = $pending[0]['id'];

        // 管理员按“举报成立”处理其中一条 → 资料下架，同资料其余举报一并结案
        $this->withToken($adminToken)->postJson('/api/v1/admin/material-reports/'.$reportId.'/handle', [
            'decision' => 'upheld',
            'handling_note' => '经核实存在侵权，已下架。',
        ])->assertOk()
            ->assertJsonPath('data.handled', true)
            ->assertJsonPath('data.resolved_count', 2);

        // 资料已下架，不在公开列表
        unset($this->defaultHeaders['Authorization']);
        $this->getJson('/api/v1/materials')->assertOk()->assertJsonPath('data.pagination.total', 0);

        $material = Material::query()->where('public_id', $materialId)->first();
        $this->assertSame('offline', $material->status);
        $this->assertSame('经核实存在侵权，已下架。', $material->reject_reason);

        // 两条举报均结案且结论一致
        $reports = MaterialReport::query()->where('material_id', $material->id)->get();
        $this->assertCount(2, $reports);
        foreach ($reports as $r) {
            $this->assertSame('handled', $r->status);
            $this->assertSame('upheld', $r->decision);
        }

        // 通知闭环：作者收到下架通知，两位举报人均收到处理结果
        $this->assertSame(1, Notification::query()->where('user_id', $uploader->id)->where('scene_code', 'material_takedown')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $reporterA->id)->where('scene_code', 'material_report_result')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $reporterB->id)->where('scene_code', 'material_report_result')->count());

        // 幂等：重复处理不追加通知
        $before = Notification::query()->count();
        $this->withToken($adminToken)->postJson('/api/v1/admin/material-reports/'.$reportId.'/handle', [
            'decision' => 'upheld',
        ])->assertOk()->assertJsonPath('data.idempotent', true);
        $this->assertSame($before, Notification::query()->count());
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
