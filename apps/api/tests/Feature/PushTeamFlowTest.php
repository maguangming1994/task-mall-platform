<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Push\Models\PushPosition;
use App\Modules\Push\Models\PushSubmission;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamApplication;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushTeamFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_end_to_end_push_flow_from_team_application_to_wallet_settlement(): void
    {
        $campus = $this->campus('push-main', '东湖校区');
        $admin = $this->admin('push-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];

        $leader = $this->user($campus, '团长陈曦', '13800139001');
        $member = $this->user($campus, '成员林昭', '13800139002');
        $this->verifyIdentity($member);

        $team = $this->withToken($adminToken)->withHeaders($headers)->postJson('/api/v1/admin/push/teams', [
            'name' => '东湖校园推广大队',
            'leader_user_id' => $leader->public_id,
            'status' => 'recruiting',
        ])->assertCreated()->json('data');
        $this->assertSame(1, $team['member_count']);

        $application = $this->withToken($this->tokenFor($member))
            ->postJson('/api/v1/push/teams/'.$team['id'].'/apply', ['message' => '希望加入'])
            ->assertCreated()->json('data');
        $this->assertSame('pending', $application['status']);

        $this->withToken($this->tokenFor($leader))
            ->postJson('/api/v1/push/applications/'.$application['id'].'/review', ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $task = $this->withToken($adminToken)->withHeaders($headers)->postJson('/api/v1/admin/push/tasks', [
            'title' => '快手极速版 · 校园拉新',
            'reward_amount' => 8,
            'quota' => 10,
            'per_user_limit' => 1,
            'status' => 'published',
        ])->assertCreated()->json('data');
        $this->assertSame('8.00', $task['reward_amount']);

        $memberToken = $this->tokenFor($member);
        $position = $this->withToken($memberToken)
            ->postJson('/api/v1/push/tasks/'.$task['id'].'/positions', ['keyword' => '快手极速版'])
            ->assertCreated()->json('data');
        $this->assertSame('active', $position['status']);

        $submission = $this->withToken($memberToken)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/submissions', [
                'images' => ['/storage/push/proof-1.jpg'],
                'remark' => '现场注册 3 人',
            ])->assertCreated()->json('data');
        $this->assertSame('pending', $submission['status']);

        $reviewed = $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/submissions/'.$submission['id'].'/review', ['decision' => 'approve'])
            ->assertOk()->json('data');
        $this->assertSame('approved', $reviewed['status']);
        $this->assertSame('8.00', $reviewed['commission']);

        $settlement = $this->withToken($adminToken)->withHeaders($headers)->postJson('/api/v1/admin/push/settlements', [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertSame(1, $settlement['submission_count']);

        $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/settlements/'.$settlement['id'].'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/settlements/'.$settlement['id'].'/pay')
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $member->id, 'wallet_available_cents' => 800]);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $member->id, 'business_type' => 'push_commission', 'change_available_cents' => 800]);

        $earnings = $this->withToken($memberToken)->getJson('/api/v1/push/earnings')->assertOk();
        $this->assertSame('8.00', $earnings->json('data.wallet.settled_amount'));
    }

    public function test_push_data_is_isolated_by_campus(): void
    {
        $campusA = $this->campus('push-a', '东湖校区');
        $campusB = $this->campus('push-b', '城北校区');
        $admin = $this->admin('push-cross');
        $adminToken = $this->adminLogin($admin);

        PushTeam::query()->create(['campus_id' => $campusA->id, 'name' => 'A 校团队', 'status' => 'recruiting']);
        PushTeam::query()->create(['campus_id' => $campusB->id, 'name' => 'B 校团队', 'status' => 'recruiting']);

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campusA->public_id])
            ->getJson('/api/v1/admin/push/teams')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'A 校团队');

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campusB->public_id])
            ->getJson('/api/v1/admin/push/teams')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'B 校团队');
    }

    public function test_leader_application_creates_team_after_admin_approval(): void
    {
        $campus = $this->campus('push-leader', '南苑校区');
        $admin = $this->admin('push-leader-ops');
        $adminToken = $this->adminLogin($admin);
        $applicant = $this->user($campus, '申请人许南', '13800139003');
        $this->verifyIdentity($applicant);
        $applicantToken = $this->tokenFor($applicant);

        $application = $this->withToken($applicantToken)
            ->postJson('/api/v1/push/leader-applications', ['team_name' => '南苑新媒体小组', 'message' => '希望承接校园推广'])
            ->assertCreated()->json('data');
        $this->assertSame('leader', $application['type']);

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campus->public_id])
            ->postJson('/api/v1/admin/push/applications/'.$application['id'].'/review', ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $team = PushTeam::query()->where('name', '南苑新媒体小组')->first();
        $this->assertNotNull($team);
        $this->assertSame($applicant->id, $team->leader_user_id);
        $this->assertSame(1, $team->member_count);
    }

    public function test_rejecting_submission_requires_reason_and_does_not_pay(): void
    {
        $campus = $this->campus('push-reject', '西区校区');
        $admin = $this->admin('push-reject-ops');
        $adminToken = $this->adminLogin($admin);
        $member = $this->user($campus, '成员赵一鸣', '13800139004');
        $team = PushTeam::query()->create(['campus_id' => $campus->id, 'name' => '西区战队', 'status' => 'active', 'leader_user_id' => $member->id]);
        $position = PushPosition::query()->create([
            'campus_id' => $campus->id, 'task_id' => $this->task($campus)->id, 'user_id' => $member->id,
            'team_id' => $team->id, 'status' => 'active', 'claimed_at' => now(),
        ]);
        $submission = PushSubmission::query()->create([
            'campus_id' => $campus->id, 'task_id' => $position->task_id, 'position_id' => $position->id,
            'user_id' => $member->id, 'team_id' => $team->id, 'status' => 'pending', 'images' => ['/proof.jpg'],
        ]);

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campus->public_id])
            ->postJson('/api/v1/admin/push/submissions/'.$submission->public_id.'/review', ['decision' => 'reject'])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campus->public_id])
            ->postJson('/api/v1/admin/push/submissions/'.$submission->public_id.'/review', ['decision' => 'reject', 'reason' => '凭证不清晰'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $member->id, 'business_type' => 'push_commission']);
    }

    private function campus(string $code, string $name): Campus
    {
        return Campus::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'isolation_mode' => 'strict',
        ]);
    }

    private function admin(string $account): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $admin;
    }

    private function adminLogin(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }

    private function user(Campus $campus, string $nickname, string $phone): User
    {
        return User::factory()->create([
            'campus_id' => $campus->id,
            'nickname' => $nickname,
            'phone' => $phone,
            'phone_verified_at' => now(),
            'grade_year' => 2025,
            'major' => '计算机科学',
            'status' => 'active',
        ]);
    }

    private function verifyIdentity(User $user): void
    {
        UserIdentityVerification::query()->create([
            'user_id' => $user->id,
            'provider' => 'manual',
            'status' => 'verified',
            'real_name_masked' => '林*',
            'identity_number_masked' => '3601**********1234',
            'identity_number_hash' => hash('sha256', 'push-test-identity-'.$user->id),
            'verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        if (isset($this->tokens[$user->id])) {
            return $this->tokens[$user->id];
        }
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'push-test-'.$user->id),
            'client_platform' => 'h5_browser',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'push-test-refresh-'.$user->id),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return $this->tokens[$user->id] = app(AccessTokenService::class)->issue($user, $session)['token'];
    }

    private function task(Campus $campus): \App\Modules\Push\Models\PushTask
    {
        return \App\Modules\Push\Models\PushTask::query()->create([
            'campus_id' => $campus->id,
            'title' => '测试推广任务',
            'reward_cents' => 500,
            'quota' => 10,
            'per_user_limit' => 1,
            'status' => 'published',
        ]);
    }
}
