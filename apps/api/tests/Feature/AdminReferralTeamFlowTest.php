<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Growth\Models\Referral;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReferralTeamFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_team_list_and_detail_report_descendant_performance_with_explicit_units(): void
    {
        $admin = $this->admin('referral-team-root', 'super_admin');
        $token = $this->login($admin);
        $campus = Campus::query()->create(['code' => 'team-campus', 'name' => '团队学校', 'status' => 'active']);
        $leader = $this->user($campus, 'TeamLeader', '13800138201');
        $directQualified = $this->user($campus, '直属有效成员', '13800138202');
        $directTracking = $this->user($campus, '直属成长成员', '13800138203');
        $indirectQualified = $this->user($campus, '二级有效成员', '13800138204');
        $disabledMember = $this->user($campus, '三级停用成员', '13800138205', 'disabled');

        $directReferral = $this->referral($leader, $directQualified, true);
        $this->referral($leader, $directTracking, false);
        $indirectReferral = $this->referral($directQualified, $indirectQualified, true);
        $disabledReferral = $this->referral($indirectQualified, $disabledMember, true);

        [$taskId, $versionId] = $this->taskContext($admin, $campus, 'team-performance');
        $leaderClaim = $this->approvedClaim($leader, $taskId, $versionId, 'leader');
        $directClaim = $this->approvedClaim($directQualified, $taskId, $versionId, 'direct');
        $this->approvedClaim($directTracking, $taskId, $versionId, 'approved-without-timestamp', false);
        $this->approvedClaim($indirectQualified, $taskId, $versionId, 'indirect-1');
        $this->approvedClaim($indirectQualified, $taskId, $versionId, 'indirect-2');
        $this->deliveredReward($admin, $taskId, $versionId, $directClaim, 1, 'points', 100);
        $this->deliveredReward($admin, $taskId, $versionId, $directClaim, 2, 'wallet_cash', 250);
        $this->deliveredReward($admin, $taskId, $versionId, $directClaim, 3, 'coupon', 2);
        $this->deliveredReward($admin, $taskId, $versionId, $leaderClaim, 4, 'points', 999);

        $this->completedOrder($leader, $campus, 'LEADER-ORDER', 900, 0);
        $this->completedOrder($directQualified, $campus, 'DIRECT-ORDER', 1000, 200);
        $this->completedOrder($indirectQualified, $campus, 'INDIRECT-ORDER', 500, 600);
        $this->referralReward($leader, $directReferral, 999);
        $this->referralReward($directQualified, $indirectReferral, 30);
        $this->referralReward($indirectQualified, $disabledReferral, 40);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?keyword=TeamLeader&page_size=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.metric_scope', 'descendants_only')
            ->assertJsonPath('data.items.0.id', $leader->public_id)
            ->assertJsonPath('data.items.0.leader.phone_masked', '138****8201')
            ->assertJsonPath('data.items.0.direct_member_count', 2)
            ->assertJsonPath('data.items.0.member_count', 4)
            ->assertJsonPath('data.items.0.active_member_count', 3)
            ->assertJsonPath('data.items.0.effective_member_count', 2)
            ->assertJsonPath('data.items.0.max_depth', 3)
            ->assertJsonPath('data.items.0.traversal.truncated', false)
            ->assertJsonPath('data.items.0.performance.approved_tasks', 3)
            ->assertJsonPath('data.items.0.performance.task_reward_points', 100)
            ->assertJsonPath('data.items.0.performance.task_reward_wallet_cents', 250)
            ->assertJsonPath('data.items.0.performance.task_reward_coupons', 2)
            ->assertJsonPath('data.items.0.performance.paid_orders', 2)
            ->assertJsonPath('data.items.0.performance.paid_order_gross_amount_cents', 1500)
            ->assertJsonPath('data.items.0.performance.paid_order_refunded_amount_cents', 800)
            ->assertJsonPath('data.items.0.performance.paid_order_net_amount_cents', 800)
            ->assertJsonPath('data.items.0.performance.paid_order_amount_cents', 800)
            ->assertJsonPath('data.items.0.performance.referral_reward_count', 2)
            ->assertJsonPath('data.items.0.performance.referral_reward_points', 70);
        $response->assertDontSee('13800138201')->assertDontSee('13800138202');

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?keyword=13800138201&page_size=10')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams/'.$leader->public_id.'?level=2&effective=true&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.team.id', $leader->public_id)
            ->assertJsonPath('data.members.pagination.total', 1)
            ->assertJsonPath('data.members.items.0.user.id', $indirectQualified->public_id)
            ->assertJsonPath('data.members.items.0.parent.id', $directQualified->public_id)
            ->assertJsonPath('data.members.items.0.level', 2)
            ->assertJsonPath('data.members.items.0.direct', false)
            ->assertJsonPath('data.members.items.0.effective', true)
            ->assertJsonPath('data.members.items.0.performance.approved_tasks', 2)
            ->assertJsonPath('data.members.items.0.performance.referral_reward_points', 40);
    }

    public function test_admin_without_user_view_permission_cannot_access_team_routes(): void
    {
        $admin = $this->admin('referral-team-no-access', null);
        $token = $this->login($admin);
        $leader = User::factory()->create(['nickname' => 'Protected-Team-Leader']);
        $member = User::factory()->create(['nickname' => 'Protected-Team-Member']);
        $this->referral($leader, $member, false);

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?page_size=10')
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams/'.$leader->public_id.'?page_size=10')
            ->assertForbidden();
    }

    public function test_revoked_edge_cuts_off_its_entire_descendant_branch(): void
    {
        $admin = $this->admin('referral-revoked-root', 'super_admin');
        $token = $this->login($admin);
        $leader = User::factory()->create(['nickname' => 'Revoked-Branch-Leader']);
        $visibleMember = User::factory()->create(['nickname' => 'Visible-Branch-Member']);
        $revokedMember = User::factory()->create(['nickname' => 'Revoked-Branch-Member']);
        $hiddenDescendant = User::factory()->create(['nickname' => 'Hidden-Branch-Descendant']);
        $this->referral($leader, $visibleMember, false);
        $this->referral($leader, $revokedMember, true)->update(['revoked_at' => now()]);
        $this->referral($revokedMember, $hiddenDescendant, true);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams/'.$leader->public_id.'?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.team.direct_member_count', 1)
            ->assertJsonPath('data.team.member_count', 1)
            ->assertJsonCount(1, 'data.members.items')
            ->assertJsonPath('data.members.items.0.user.id', $visibleMember->public_id);
        $response->assertDontSee($revokedMember->public_id)->assertDontSee($hiddenDescendant->public_id);
    }

    public function test_team_list_query_count_is_nearly_constant_as_team_count_grows(): void
    {
        $admin = $this->admin('referral-query-budget', 'super_admin');
        $token = $this->login($admin);
        foreach (range(1, 12) as $number) {
            $leader = User::factory()->create(['nickname' => sprintf('QueryBudgetLeader-%02d', $number)]);
            $member = User::factory()->create(['nickname' => sprintf('QueryBudgetMember-%02d', $number)]);
            $this->referral($leader, $member, false);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?keyword=QueryBudgetLeader-01&page_size=50')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $singleTeamQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?keyword=QueryBudgetLeader&page_size=50')
            ->assertOk()
            ->assertJsonCount(12, 'data.items');
        $manyTeamQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleTeamQueries + 2, $manyTeamQueries);
        $this->assertLessThanOrEqual(30, $manyTeamQueries);
    }

    public function test_list_member_cap_is_explicit_and_detail_can_traverse_beyond_it(): void
    {
        $admin = $this->admin('referral-list-cap', 'super_admin');
        $token = $this->login($admin);
        $leader = User::factory()->create(['nickname' => 'List-Cap-Leader']);
        $now = now();
        $users = [];
        foreach (range(1, 1001) as $number) {
            $users[] = [
                'public_id' => (string) Str::ulid(),
                'nickname' => sprintf('ListCapMember-%04d', $number),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($users, 50) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $referrals = DB::table('users')
            ->where('nickname', 'like', 'ListCapMember-%')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $memberId): array => [
                'public_id' => (string) Str::ulid(),
                'referrer_user_id' => $leader->id,
                'referred_user_id' => (int) $memberId,
                'referral_code' => 'LISTCAP',
                'bound_at' => $now,
                'status' => 'tracking',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();
        foreach (array_chunk($referrals, 50) as $chunk) {
            DB::table('referrals')->insert($chunk);
        }

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams?keyword=List-Cap-Leader&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.member_count', 1000)
            ->assertJsonPath('data.items.0.traversal.member_limit', 1000)
            ->assertJsonPath('data.items.0.traversal.truncated', true);

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/referral-teams/'.$leader->public_id.'?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.team.member_count', 1001)
            ->assertJsonPath('data.team.traversal.member_limit', 5000)
            ->assertJsonPath('data.team.traversal.truncated', false)
            ->assertJsonPath('data.members.pagination.total', 1001);
    }

    public function test_cycle_and_excessive_depth_are_deduplicated_and_explicitly_truncated(): void
    {
        $admin = $this->admin('referral-cycle-root', 'super_admin');
        $token = $this->login($admin);
        $cycleA = User::factory()->create(['nickname' => 'Cycle-A']);
        $cycleB = User::factory()->create(['nickname' => 'Cycle-B']);
        $this->referral($cycleA, $cycleB, false);
        $this->referral($cycleB, $cycleA, false);

        $this->withToken($token)->getJson('/api/v1/admin/user-center/referral-teams?keyword=Cycle-A&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.member_count', 1)
            ->assertJsonPath('data.items.0.max_depth', 1)
            ->assertJsonPath('data.items.0.traversal.truncated', false);

        $deepLeader = User::factory()->create(['nickname' => 'Deep-Team-Leader']);
        $parent = $deepLeader;
        foreach (range(1, 10) as $depth) {
            $member = User::factory()->create(['nickname' => 'Deep-'.$depth]);
            $this->referral($parent, $member, false);
            $parent = $member;
        }

        $this->withToken($token)->getJson('/api/v1/admin/user-center/referral-teams/'.$deepLeader->public_id.'?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.team.member_count', 8)
            ->assertJsonPath('data.team.max_depth', 8)
            ->assertJsonPath('data.team.traversal.max_depth_limit', 8)
            ->assertJsonPath('data.team.traversal.truncated', true)
            ->assertJsonPath('data.members.pagination.total', 8)
            ->assertJsonCount(8, 'data.members.items');
    }

    public function test_campus_agent_only_sees_same_campus_edges_and_performance(): void
    {
        $campus = Campus::query()->create(['code' => 'scoped-team', 'name' => '本校', 'status' => 'active']);
        $outsideCampus = Campus::query()->create(['code' => 'outside-team', 'name' => '外校', 'status' => 'active']);
        $agent = $this->admin('referral-team-agent', 'campus_agent');
        $agent->campuses()->attach($campus->id, ['is_primary' => true]);
        $token = $this->login($agent);
        $localLeader = $this->user($campus, '本校团队负责人', '13800138301');
        $localMember = $this->user($campus, '本校直属成员', '13800138302');
        $outsideMember = $this->user($outsideCampus, '外校跨校成员', '13800138303');
        $outsideChild = $this->user($outsideCampus, '外校团队成员', '13800138304');
        $this->referral($localLeader, $localMember, true);
        $this->referral($localMember, $outsideMember, true);
        $this->referral($outsideMember, $outsideChild, true);
        $this->completedOrder($outsideMember, $outsideCampus, 'OUTSIDE-ORDER', 999999, 0);

        $this->withToken($token)->getJson('/api/v1/admin/user-center/referral-teams?page_size=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $localLeader->public_id)
            ->assertJsonPath('data.items.0.member_count', 1)
            ->assertJsonPath('data.items.0.performance.paid_orders', 0)
            ->assertJsonPath('data.items.0.performance.paid_order_net_amount_cents', 0);

        $this->withToken($token)->getJson('/api/v1/admin/user-center/referral-teams/'.$outsideMember->public_id.'?page_size=10')
            ->assertNotFound();

        $this->withToken($token)->getJson('/api/v1/admin/user-center/referrals?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.inviter.id', $localLeader->public_id)
            ->assertJsonPath('data.items.0.referred_user.id', $localMember->public_id);
    }

    private function admin(string $account, ?string $role): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        if ($role !== null) {
            $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));
        }

        return $admin;
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }

    private function user(Campus $campus, string $nickname, string $phone, string $status = 'active'): User
    {
        return User::factory()->create([
            'campus_id' => $campus->id,
            'nickname' => $nickname,
            'phone' => $phone,
            'phone_verified_at' => now(),
            'status' => $status,
        ]);
    }

    private function referral(User $referrer, User $referred, bool $qualified): Referral
    {
        return Referral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'TEST'.strtoupper(substr($referrer->public_id, -10)),
            'bound_at' => now()->subDays(10),
            'status' => $qualified ? 'rewarded' : 'tracking',
            'qualified_at' => $qualified ? now()->subDays(2) : null,
            'rewarded_at' => $qualified ? now()->subDay() : null,
        ]);
    }

    /** @return array{int, int} */
    private function taskContext(AdminUser $admin, Campus $campus, string $key): array
    {
        $now = now();
        $taskId = DB::table('tasks')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'campus_id' => $campus->id,
            'created_by_admin_id' => $admin->id,
            'creation_channel' => 'admin_web',
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'lock_version' => 1,
            'idempotency_key_hash' => hash('sha256', $key),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionId = DB::table('task_versions')->insertGetId([
            'task_id' => $taskId,
            'version_no' => 1,
            'status' => 'published',
            'change_type' => 'initial',
            'changed_fields' => json_encode([]),
            'content' => json_encode([]),
            'created_by_admin_id' => $admin->id,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$taskId, $versionId];
    }

    private function approvedClaim(User $user, int $taskId, int $versionId, string $key, bool $withApprovedAt = true): int
    {
        $now = now();

        return DB::table('task_claims')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'task_id' => $taskId,
            'task_version_id' => $versionId,
            'user_id' => $user->id,
            'enrollment_source' => 'claim',
            'cycle_key' => $key,
            'task_snapshot' => json_encode([]),
            'status' => 'approved',
            'execution_status' => 'completed',
            'review_status' => 'approved',
            'slot_status' => 'issued',
            'completion_deadline_at' => $now,
            'reward_status' => 'delivered',
            'claim_idempotency_key_hash' => hash('sha256', 'claim-'.$key),
            'approved_at' => $withApprovedAt ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function deliveredReward(
        AdminUser $admin,
        int $taskId,
        int $versionId,
        int $claimId,
        int $sequence,
        string $assetType,
        int $amount,
    ): void {
        $now = now();
        $key = $claimId.'-'.$sequence;
        $poolId = DB::table('reward_budget_pools')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name' => '团队业绩测试预算-'.$key,
            'asset_type' => $assetType,
            'total_amount' => $amount,
            'locked_amount' => 0,
            'issued_amount' => $amount,
            'status' => 'active',
            'version' => 1,
            'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'pool-'.$key),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $budgetId = DB::table('task_reward_budgets')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'task_id' => $taskId,
            'task_version_id' => $versionId,
            'reward_budget_pool_id' => $poolId,
            'reward_sequence' => $sequence,
            'asset_type' => $assetType,
            'unit_amount' => $amount,
            'max_deliveries_total' => 1,
            'locked_amount' => $amount,
            'reserved_amount' => 0,
            'issued_amount' => $amount,
            'released_amount' => 0,
            'recovered_amount' => 0,
            'status' => 'settled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $reservationId = DB::table('task_reward_reservations')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'task_claim_id' => $claimId,
            'task_reward_budget_id' => $budgetId,
            'reward_sequence' => $sequence,
            'asset_type' => $assetType,
            'amount' => $amount,
            'status' => 'issued',
            'idempotency_key_hash' => hash('sha256', 'reservation-'.$key),
            'reserved_at' => $now,
            'settled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('task_reward_deliveries')->insert([
            'public_id' => (string) Str::ulid(),
            'task_claim_id' => $claimId,
            'task_reward_reservation_id' => $reservationId,
            'asset_type' => $assetType,
            'amount' => $amount,
            'reward_snapshot' => json_encode(['type' => $assetType]),
            'status' => 'delivered',
            'attempt_count' => 1,
            'delivered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function completedOrder(User $user, Campus $campus, string $key, int $grossCents, int $refundedCents): void
    {
        $now = now();
        $previewId = DB::table('checkout_previews')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'preview-'.$key),
            'request_hash' => hash('sha256', 'request-'.$key),
            'request_snapshot' => json_encode([]),
            'pricing_snapshot' => json_encode([]),
            'expires_at' => $now->copy()->addHour(),
            'consumed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $batchId = DB::table('order_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'batch_no' => 'BATCH-'.$key,
            'user_id' => $user->id,
            'client_platform' => 'h5-browser',
            'capability_version' => 1,
            'status' => 'completed',
            'idempotency_key_hash' => hash('sha256', 'batch-'.$key),
            'checkout_preview_id' => $previewId,
            'items_cash_amount' => $grossCents,
            'items_points' => 0,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 0,
            'payable_cash_amount' => $grossCents,
            'payable_points' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('orders')->insert([
            'public_id' => (string) Str::ulid(),
            'campus_id' => $campus->id,
            'order_no' => $key,
            'order_batch_id' => $batchId,
            'user_id' => $user->id,
            'fulfillment_type' => 'virtual',
            'status' => $refundedCents > 0 ? 'partially_refunded' : 'completed',
            'cash_payment_method' => 'wechat',
            'items_cash_amount' => $grossCents,
            'items_points' => 0,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 0,
            'payable_cash_amount' => $grossCents,
            'payable_points' => 0,
            'paid_wallet_amount' => 0,
            'paid_external_amount' => $grossCents,
            'paid_points' => 0,
            'refunded_wallet_amount' => 0,
            'refunded_external_amount' => $refundedCents,
            'refunded_points' => 0,
            'paid_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function referralReward(User $recipient, Referral $referral, int $points): void
    {
        DB::table('point_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $recipient->id,
            'transaction_type' => 'grant',
            'change_available' => $points,
            'change_frozen' => 0,
            'change_debt' => 0,
            'available_after' => $points,
            'frozen_after' => 0,
            'debt_after' => 0,
            'source_code' => 'referral_new_user',
            'source_business_id' => $referral->public_id,
            'event_key_hash' => hash('sha256', 'referral-reward-'.$referral->public_id),
            'reason' => '团队业绩测试邀请奖励',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
