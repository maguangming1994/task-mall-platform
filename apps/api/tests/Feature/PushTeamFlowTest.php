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
use App\Modules\Push\Models\PushSettlement;
use App\Modules\Push\Models\PushSubmission;
use App\Modules\Push\Models\PushTask;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamApplication;
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

    public function test_suspended_team_member_cannot_claim_or_submit(): void
    {
        $campus = $this->campus('push-susp', '暂停校区');
        $admin = $this->admin('push-susp-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $user = $this->user($campus, '推广员韩梅', '13800139201');
        $team = $this->pushTeam($campus, $user, '暂停测试队');
        $team->forceFill(['status' => 'suspended'])->save();
        $token = $this->tokenFor($user);

        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 1])->save();

        $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '暂停'])
            ->assertStatus(422);

        $position = PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position->public_id.'/submissions', ['images' => ['/proof.jpg']])
            ->assertStatus(422);

        $this->assertDatabaseCount('push_submissions', 0);
        $this->assertSame('active', $position->fresh()->status);
        $this->assertSame(1, (int) $task->fresh()->claimed_count);
    }

    public function test_leaving_team_closes_active_positions_and_releases_quota(): void
    {
        $campus = $this->campus('push-leave', '退团校区');
        $leader = $this->user($campus, '团长周涛', '13800139202');
        $member = $this->user($campus, '成员吴雨', '13800139203');
        $team = $this->pushTeam($campus, $leader, '退团测试队');
        PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        app(PushService::class)->syncMemberCount($team);

        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 1])->save();
        $position = PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $member->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($this->tokenFor($member))
            ->postJson('/api/v1/push/my-team/leave')
            ->assertOk();

        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertNotNull($position->closed_at);
        $this->assertSame(0, (int) $task->fresh()->claimed_count);
        $this->assertSame(1, (int) $team->fresh()->member_count);
    }

    public function test_join_application_cannot_be_approved_when_team_not_recruiting(): void
    {
        $campus = $this->campus('push-closed-team', '关闭校区');
        $leader = $this->user($campus, '团长郑云', '13800139204');
        $applicant = $this->user($campus, '申请人冯楠', '13800139205');
        $team = $this->pushTeam($campus, $leader, '关闭测试队');
        $team->forceFill(['status' => 'closed'])->save();
        $application = PushTeamApplication::query()->create([
            'campus_id' => $campus->id,
            'type' => 'join',
            'team_id' => $team->id,
            'user_id' => $applicant->id,
            'message' => '希望加入',
            'status' => 'pending',
        ]);

        $this->withToken($this->tokenFor($leader))
            ->postJson('/api/v1/push/applications/'.$application->public_id.'/review', ['decision' => 'approve'])
            ->assertStatus(422);

        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(1, (int) $team->fresh()->member_count);
    }

    public function test_member_limit_cannot_be_lower_than_active_members(): void
    {
        $campus = $this->campus('push-limit', '上限校区');
        $admin = $this->admin('push-limit-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长陆一', '13800139206');
        $member = $this->user($campus, '成员丁二', '13800139207');
        $team = $this->pushTeam($campus, $leader, '上限测试队');
        PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        app(PushService::class)->syncMemberCount($team);
        $this->assertSame(2, (int) $team->fresh()->member_count);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['member_limit' => 1])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['member_limit' => 2])
            ->assertOk();
        $this->assertSame(2, (int) $team->fresh()->member_limit);
    }

    public function test_update_task_quota_cannot_be_lower_than_claimed_count(): void
    {
        $campus = $this->campus('push-quota', '名额校区');
        $admin = $this->admin('push-quota-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 5])->save();

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['quota' => 3])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['quota' => 5])
            ->assertOk();
        $this->assertSame(5, (int) $task->fresh()->quota);
    }

    public function test_per_user_limit_ignores_closed_positions(): void
    {
        $campus = $this->campus('push-limit-closed', '限领校区');
        $user = $this->user($campus, '推广员蒋三', '13800139301');
        $team = $this->pushTeam($campus, $user, '限领测试队');
        $token = $this->tokenFor($user);

        $task = $this->task($campus);
        $task->forceFill(['per_user_limit' => 1])->save();
        PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'closed',
            'claimed_at' => now()->subDays(3),
            'closed_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '重新领取'])
            ->assertCreated();

        $this->assertSame(1, PushPosition::query()->where('task_id', $task->id)->where('status', 'active')->count());
    }

    public function test_closing_team_closes_positions_and_removes_members(): void
    {
        $campus = $this->campus('push-team-close', '关团校区');
        $admin = $this->admin('push-team-close-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长许一', '13800139302');
        $member = $this->user($campus, '成员何二', '13800139303');
        $team = $this->pushTeam($campus, $leader, '关团测试队');
        PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        app(PushService::class)->syncMemberCount($team);

        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 2])->save();
        $positions = collect([$leader, $member])->map(fn (User $user) => PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addDay(),
        ]));

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'closed'])
            ->assertOk();

        foreach ($positions as $position) {
            $this->assertSame('closed', $position->fresh()->status);
        }
        $this->assertSame(0, (int) $task->fresh()->claimed_count);
        $this->assertSame(0, (int) $team->fresh()->member_count);
        $this->assertDatabaseHas('push_team_members', ['team_id' => $team->id, 'user_id' => $leader->id, 'status' => 'left']);
        $this->assertDatabaseHas('push_team_members', ['team_id' => $team->id, 'user_id' => $member->id, 'status' => 'left']);
    }

    public function test_suspending_team_keeps_members_but_closes_positions(): void
    {
        $campus = $this->campus('push-team-susp', '暂停联团校区');
        $admin = $this->admin('push-team-susp-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长沈三', '13800139304');
        $team = $this->pushTeam($campus, $leader, '暂停联团队');
        $task = $this->task($campus);
        $task->forceFill(['claimed_count' => 1])->save();
        $position = PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $leader->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->claimed_count);
        $this->assertSame(1, (int) $team->fresh()->member_count);
        $this->assertDatabaseHas('push_team_members', ['team_id' => $team->id, 'user_id' => $leader->id, 'status' => 'active']);
    }

    public function test_member_cannot_be_reactivated_when_team_closed_or_full(): void
    {
        $campus = $this->campus('push-reactivate', '恢复校区');
        $admin = $this->admin('push-reactivate-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];

        $closedLeader = $this->user($campus, '团长曹四', '13800139305');
        $closedMember = $this->user($campus, '成员严五', '13800139306');
        $closedTeam = $this->pushTeam($campus, $closedLeader, '关闭恢复队');
        $closedTeam->forceFill(['status' => 'closed'])->save();
        $closedRecord = PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $closedTeam->id,
            'user_id' => $closedMember->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'left',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now()->subDay(),
            'left_at' => now(),
        ]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/members/'.$closedRecord->id, ['status' => 'active'])
            ->assertStatus(422);

        $fullLeader = $this->user($campus, '团长魏六', '13800139307');
        $fullMember = $this->user($campus, '成员陶七', '13800139308');
        $fullTeam = $this->pushTeam($campus, $fullLeader, '满员恢复队');
        $fullTeam->forceFill(['member_limit' => 1])->save();
        $fullRecord = PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $fullTeam->id,
            'user_id' => $fullMember->id,
            'role' => 'member',
            'level_code' => 'A1',
            'status' => 'left',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now()->subDay(),
            'left_at' => now(),
        ]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/members/'.$fullRecord->id, ['status' => 'active'])
            ->assertStatus(422);

        $this->assertSame('left', $closedRecord->fresh()->status);
        $this->assertSame('left', $fullRecord->fresh()->status);
    }

    public function test_positions_expose_latest_submission_status(): void
    {
        $campus = $this->campus('push-position-state', '交单状态校区');
        $user = $this->user($campus, '推广员秦八', '13800139309');
        $team = $this->pushTeam($campus, $user, '交单状态队');
        $token = $this->tokenFor($user);

        $task = $this->task($campus);
        $position = PushPosition::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        PushSubmission::query()->create([
            'campus_id' => $campus->id,
            'task_id' => $task->id,
            'position_id' => $position->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'level_code' => 'A1',
            'images' => ['/proof.jpg'],
            'status' => 'rejected',
            'reject_reason' => '凭证模糊',
            'reviewed_at' => now(),
        ]);

        $items = $this->withToken($token)->getJson('/api/v1/push/positions')->assertOk()->json('data.items');
        $item = collect($items)->firstWhere('id', $position->public_id);

        $this->assertNotNull($item);
        $this->assertSame('rejected', $item['latest_submission']['status']);
        $this->assertSame('凭证模糊', $item['latest_submission']['reject_reason']);
    }

    public function test_closed_team_clears_leader_and_requires_leader_before_reopen(): void
    {
        $campus = $this->campus('push-reopen', '重开校区');
        $admin = $this->admin('push-reopen-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长任七', '13800139401');
        $team = $this->pushTeam($campus, $leader, '重开测试队');

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'closed'])
            ->assertOk();

        $this->assertNull($team->fresh()->leader_user_id);
        $this->assertSame(0, (int) $team->fresh()->member_count);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'recruiting'])
            ->assertStatus(422);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, [
                'status' => 'recruiting',
                'leader_user_id' => $leader->public_id,
            ])
            ->assertOk();

        $this->assertSame((int) $leader->id, (int) $team->fresh()->leader_user_id);
        $this->assertSame(1, (int) $team->fresh()->member_count);
        $this->assertDatabaseHas('push_team_members', [
            'team_id' => $team->id,
            'user_id' => $leader->id,
            'role' => 'leader',
            'status' => 'active',
        ]);
    }

    public function test_team_status_change_rejects_pending_join_applications(): void
    {
        $campus = $this->campus('push-app-clean', '申请清理校区');
        $admin = $this->admin('push-app-clean-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长白九', '13800139402');
        $applicant = $this->user($campus, '申请人顾十', '13800139403');
        $team = $this->pushTeam($campus, $leader, '申请清理队');
        $application = PushTeamApplication::query()->create([
            'campus_id' => $campus->id,
            'type' => 'join',
            'team_id' => $team->id,
            'user_id' => $applicant->id,
            'message' => '希望加入',
            'status' => 'pending',
        ]);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertNotNull($application->fresh()->review_note);
    }

    public function test_releasing_position_returns_quota_and_frees_per_user_limit(): void
    {
        $campus = $this->campus('push-release', '放弃校区');
        $leader = $this->user($campus, '团长韩雪', '13800139501');
        $team = $this->pushTeam($campus, $leader, '放弃测试队');
        $task = $this->task($campus);
        $task->forceFill(['quota' => 5, 'per_user_limit' => 1])->save();
        $token = $this->tokenFor($leader);

        $position = $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '放弃测试'])
            ->assertCreated()->json('data');
        $this->assertSame(1, (int) $task->fresh()->claimed_count);

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/release')
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertSame(0, (int) $task->fresh()->claimed_count);
        $this->assertSame('closed', (string) PushPosition::query()->where('public_id', $position['id'])->value('status'));

        // 放弃后不占用限领次数，可以重新领取
        $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '重新领取'])
            ->assertCreated();
    }

    public function test_releasing_position_with_pending_submission_is_rejected(): void
    {
        $campus = $this->campus('push-release-blocked', '放弃拦截校区');
        $leader = $this->user($campus, '团长沈舟', '13800139502');
        $team = $this->pushTeam($campus, $leader, '放弃拦截队');
        $task = $this->task($campus);
        $token = $this->tokenFor($leader);

        $position = $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '拦截测试'])
            ->assertCreated()->json('data');
        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/submissions', [
                'images' => ['/storage/push/proof-release.jpg'],
            ])->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/release')
            ->assertStatus(422);

        $this->assertSame('active', (string) PushPosition::query()->where('public_id', $position['id'])->value('status'));
    }

    public function test_closing_task_closes_active_positions_and_returns_quota(): void
    {
        $campus = $this->campus('push-task-close', '任务关闭校区');
        $admin = $this->admin('push-task-close-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长许安', '13800139503');
        $team = $this->pushTeam($campus, $leader, '任务关闭队');
        $task = $this->task($campus);
        $task->forceFill(['quota' => 5])->save();

        $position = $this->withToken($this->tokenFor($leader))
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '关闭联动'])
            ->assertCreated()->json('data');
        $this->assertSame(1, (int) $task->fresh()->claimed_count);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['status' => 'closed'])
            ->assertOk();

        $this->assertSame(0, (int) $task->fresh()->claimed_count);
        $this->assertSame('closed', (string) PushPosition::query()->where('public_id', $position['id'])->value('status'));
    }

    public function test_draft_settlement_cannot_be_paid(): void
    {
        $campus = $this->campus('push-draft-pay', '草稿打款校区');
        $admin = $this->admin('push-draft-pay-ops');
        $adminToken = $this->adminLogin($admin);
        $settlement = PushSettlement::query()->create([
            'campus_id' => $campus->id,
            'batch_no' => 'PT-DRAFT-001',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'draft',
            'submission_count' => 0,
            'total_commission_cents' => 0,
            'total_team_bonus_cents' => 0,
            'created_by_admin_id' => $admin->id,
        ]);

        $this->withToken($adminToken)->withHeaders(['X-Campus-Id' => $campus->public_id])
            ->postJson('/api/v1/admin/push/settlements/'.$settlement->public_id.'/pay')
            ->assertStatus(422);

        $this->assertSame('draft', (string) $settlement->fresh()->status);
    }

    public function test_price_change_does_not_recalculate_approved_commission(): void
    {
        $campus = $this->campus('push-price', '改价校区');
        $admin = $this->admin('push-price-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长秦朗', '13800139504');
        $team = $this->pushTeam($campus, $leader, '改价测试队');
        $task = $this->task($campus);
        $task->forceFill(['reward_cents' => 800, 'quota' => 5, 'per_user_limit' => 3])->save();
        $token = $this->tokenFor($leader);

        $first = $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '改价前'])
            ->assertCreated()->json('data');
        $firstSubmission = $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$first['id'].'/submissions', ['images' => ['/storage/push/price-1.jpg']])
            ->assertCreated()->json('data');
        $approved = $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/submissions/'.$firstSubmission['id'].'/review', ['decision' => 'approve'])
            ->assertOk()->json('data');
        $this->assertSame('8.00', $approved['commission']);

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/tasks/'.$task->public_id, ['reward_amount' => 20])
            ->assertOk();

        $this->assertSame(800, (int) PushSubmission::query()->where('public_id', $firstSubmission['id'])->value('commission_cents'));

        $second = $this->withToken($token)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '改价后'])
            ->assertCreated()->json('data');
        $secondSubmission = $this->withToken($token)
            ->postJson('/api/v1/push/positions/'.$second['id'].'/submissions', ['images' => ['/storage/push/price-2.jpg']])
            ->assertCreated()->json('data');
        $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/submissions/'.$secondSubmission['id'].'/review', ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.commission', '20.00');
    }

    public function test_team_bonus_still_goes_to_historical_leader_after_team_closed(): void
    {
        $campus = $this->campus('push-bonus-history', '历史团长大区');
        $admin = $this->admin('push-bonus-history-ops');
        $adminToken = $this->adminLogin($admin);
        $headers = ['X-Campus-Id' => $campus->public_id];
        $leader = $this->user($campus, '团长唐礼', '13800139505');
        $member = $this->user($campus, '成员方舟', '13800139506');
        $team = $this->pushTeam($campus, $leader, '历史团长队');
        PushTeamMember::query()->create([
            'campus_id' => $campus->id,
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member',
            'level_code' => 'A2',
            'status' => 'active',
            'stats_month' => now()->format('Y-m'),
            'joined_at' => now(),
        ]);
        app(PushService::class)->syncMemberCount($team);
        $task = $this->task($campus);
        $task->forceFill(['reward_cents' => 800, 'quota' => 5])->save();

        $memberToken = $this->tokenFor($member);
        $position = $this->withToken($memberToken)
            ->postJson('/api/v1/push/tasks/'.$task->public_id.'/positions', ['keyword' => '历史加成'])
            ->assertCreated()->json('data');
        $submission = $this->withToken($memberToken)
            ->postJson('/api/v1/push/positions/'.$position['id'].'/submissions', ['images' => ['/storage/push/bonus.jpg']])
            ->assertCreated()->json('data');
        $this->withToken($adminToken)->withHeaders($headers)
            ->postJson('/api/v1/admin/push/submissions/'.$submission['id'].'/review', ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.team_bonus', '0.26');

        $this->withToken($adminToken)->withHeaders($headers)
            ->putJson('/api/v1/admin/push/teams/'.$team->public_id, ['status' => 'closed'])
            ->assertOk();
        $this->assertNull($team->fresh()->leader_user_id);

        $settlement = $this->withToken($adminToken)->withHeaders($headers)->postJson('/api/v1/admin/push/settlements', [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])->assertCreated()->json('data');

        $leaderItem = collect($settlement['items'])->firstWhere('user_id', $leader->public_id);
        $this->assertNotNull($leaderItem);
        $this->assertSame('0.26', $leaderItem['team_bonus']);
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
