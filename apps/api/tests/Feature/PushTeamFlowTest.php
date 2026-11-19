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
use App\Modules\Push\Models\PushTask;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamMember;
use App\Modules\Push\Services\PushService;
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

    public function test_duplicate_submission_for_same_position_is_rejected(): void
    {
        $campus = $this->campus('push-dup', '东湖校区');
        $admin = $this->admin('push-dup-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $user = $this->user($campus, '推广员周可', '13800139101');
        $this->pushTeam($campus, $user, '重复交单测试队');
        $token = $this->tokenFor($user);

        $task = $this->withToken($adminToken)->withHeaders($headers)->postJson('/api/v1/admin/push/tasks', [
            'title' => '重复交单任务',
            'reward_amount' => 6,
            'quota' => 10,
            'per_user_limit' => 1,
            'status' => 'published',
        ])->assertCreated()->json('data');

        $position = $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task['id'].'/positions', ['keyword' => '重复测试'])
            ->assertCreated()->json('data');

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/submissions', ['images' => ['/proof-a.jpg']])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/submissions', ['images' => ['/proof-b.jpg']])
            ->assertStatus(422);

        $positionId = PushPosition::query()->where('public_id', $position['id'])->value('id');
        $this->assertSame(1, PushSubmission::query()->where('position_id', $positionId)->count());
    }

    public function test_expired_position_cannot_submit_and_is_closed(): void
    {
        $campus = $this->campus('push-exp', '过期校区');
        $admin = $this->admin('push-exp-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $user = $this->user($campus, '推广员何雨', '13800139102');
        $team = $this->pushTeam($campus, $user, '过期测试队');
        $token = $this->tokenFor($user);

        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 1])->save();
        $position = PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position->public_id.'/submissions', ['images' => ['/proof.jpg']])
            ->assertStatus(422);

        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertNotNull($position->closed_at);
        $this->assertSame(0, (int) $task->fresh()->claimed_count);

        $otherTask = $this->task($campus);
        $otherTask->forceFill(['claimed_count' => 2])->save();
        PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $otherTask->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);

        $items = $this->withToken($token)->getJson('/api/v1/push/positions')->assertOk()->json('data.items');
        $this->assertSame('closed', $items[0]['status']);
        $this->assertSame(1, (int) $otherTask->fresh()->claimed_count);
    }

    public function test_leader_cannot_be_assigned_to_two_teams(): void
    {
        $campus = $this->campus('push-leader-dup', '跨团校区');
        $admin = $this->admin('push-leader-dup-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leaderA = $this->user($campus, '团长甲', '13800139103');
        $leaderB = $this->user($campus, '团长乙', '13800139104');
        $teamA = $this->pushTeam($campus, $leaderA, '甲队');
        $teamB = $this->pushTeam($campus, $leaderB, '乙队');

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$teamB->public_id, ['leader_user_id' => $leaderA->public_id])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$teamA->public_id, ['leader_user_id' => null])
            ->assertOk();

        $this->assertNull($teamA->fresh()->leader_user_id);
        $this->assertDatabaseHas('push_team_members', [
            'team_id' => $teamA->id,
            'user_id' => $leaderA->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertSame(1, (int) $teamA->fresh()->member_count);
    }

    public function test_team_leader_cannot_be_marked_left_without_replacement(): void
    {
        $campus = $this->campus('push-leader-left', '悬空校区');
        $admin = $this->admin('push-leader-left-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长钱塘', '13800139105');
        $team = $this->pushTeam($campus, $leader, '悬空测试队');
        $leaderMember = PushTeamMember::query()
            ->where('team_id', $team->id)
            ->where('role', 'leader')
            ->firstOrFail();

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/members/'.$leaderMember->id, ['status' => 'left'])
            ->assertStatus(422);

        $this->assertSame('active', $leaderMember->fresh()->status);
        $this->assertSame(1, (int) $team->fresh()->member_count);
    }

    public function test_member_count_is_recalculated_from_active_members(): void
    {
        $campus = $this->campus('push-count', '人数校区');
        $admin = $this->admin('push-count-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长孙一', '13800139106');
        $member = $this->user($campus, '成员李二', '13800139107');
        $team = $this->pushTeam($campus, $leader, '人数一致性队');
        $this->assertSame(1, (int) $team->fresh()->member_count);

        $memberRecord = PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        PushTeam::query()->whereKey($team->id)->update(['member_count' => 99]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/members/'.$memberRecord->id, ['status' => 'left'])
            ->assertOk();
        $this->assertSame(1, (int) $team->fresh()->member_count);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/members/'.$memberRecord->id, ['status' => 'active'])
            ->assertOk();
        $this->assertSame(2, (int) $team->fresh()->member_count);
    }

    public function test_update_task_rejects_end_time_before_start_time(): void
    {
        $campus = $this->campus('push-time', '时间校区');
        $admin = $this->admin('push-time-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $task = PushTask::query()->create([
            'campus_id' => $campus->id,
            'title' => '时间校验任务',
            'reward_cents' => 500,
            'quota' => 10,
            'per_user_limit' => 1,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'status' => 'published',
        ]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['ends_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['starts_at' => now()->addDays(3)->toIso8601String()])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['ends_at' => now()->addDays(5)->toIso8601String()])
            ->assertOk();
    }

    private function pushTeam(Campus $campus, User $leader, string $name): PushTeam
    {
        $team = PushTeam::query()->create([
            'campus_id' => $campus->id,
            'name' => $name,
            'leader_user_id' => $leader->id,
            'status' => 'active',
        ]);
        PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $leader->id,
            'role' => 'leader',
            'level_code' => 'A1',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        app(PushService::class)->syncMemberCount($team);

        return $team;
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

    private function task(Campus $campus): PushTask
    {
        return PushTask::query()->create([
            'campus_id' => $campus->id,
            'title' => '测试推广任务',
            'reward_cents' => 500,
            'quota' => 10,
            'per_user_limit' => 1,
            'status' => 'published',
        ]);
    }
}
