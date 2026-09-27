<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTaskPublishingPermissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_grant_requires_rbac_reason_and_creates_a_non_login_service_principal(): void
    {
        [$user, $userToken] = $this->phoneLogin('13800138101');
        [$operationsToken] = $this->adminLogin('task-permission-operations', 'operations_admin');
        [$superToken] = $this->adminLogin('task-permission-super', 'super_admin');

        $this->withToken($userToken)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.can_publish_tasks', false);
        $this->withToken($userToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertForbidden()
            ->assertJsonPath('code', 'PUBLISHER_PERMISSION_DENIED')
            ->assertJsonPath('message', '当前账号未获任务发布权限，请联系管理员开通。');

        $path = '/api/v1/admin/user-center/users/'.$user->public_id.'/task-publishing-permission';
        $this->withToken($operationsToken)->putJson($path, ['granted' => true, 'reason' => '核验用户发布用途合规。'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $this->withToken($superToken)->putJson($path, ['granted' => true])
            ->assertUnprocessable();

        $grant = $this->withToken($superToken)->putJson($path, [
            'granted' => true,
            'reason' => '已核验用户身份及校园任务发布用途。',
        ])->assertOk()
            ->assertJsonPath('data.granted', true)
            ->assertJsonPath('data.status', 'granted');

        $publisher = TaskPublisher::query()->where('linked_user_id', $user->id)->firstOrFail();
        $proxy = $publisher->adminUser()->firstOrFail();
        $this->assertSame($publisher->public_id, $grant->json('data.publisher_id'));
        $this->assertTrue($proxy->is_service_account);
        $this->assertSame(0, $proxy->roles()->count());

        $this->withToken($userToken)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.can_publish_tasks', true);
        $this->withToken($superToken)->getJson('/api/v1/admin/user-center/users')
            ->assertOk()
            ->assertJsonPath('data.items.0.task_publishing_permission.granted', true)
            ->assertJsonPath('data.items.0.task_publishing_permission.publisher_id', $publisher->public_id);

        $proxy->update(['password' => 'KnownServicePassword123!']);
        $this->postJson('/api/v1/admin/auth/login', [
            'account' => $proxy->account,
            'password' => 'KnownServicePassword123!',
        ])->assertUnauthorized()->assertJsonPath('code', 'ADMIN_AUTH_INVALID_CREDENTIALS');
        $this->postJson('/api/v1/task-publisher/session', [
            'account' => $proxy->account,
            'password' => 'KnownServicePassword123!',
        ])->assertUnauthorized()->assertJsonPath('code', 'PUBLISHER_AUTH_INVALID');

        $audit = OperationLog::query()->where('action', 'user.task_publishing_permission_granted')->firstOrFail();
        $this->assertSame('已核验用户身份及校园任务发布用途。', $audit->reason);
        $this->assertSame($user->public_id, $audit->subject_id);
    }

    public function test_authorized_user_can_exchange_session_create_tasks_and_regrant_reuses_ownership(): void
    {
        [$user, $userToken] = $this->phoneLogin('13800138102');
        [$superToken] = $this->adminLogin('task-owner-super', 'super_admin');
        $path = '/api/v1/admin/user-center/users/'.$user->public_id.'/task-publishing-permission';
        $this->withToken($superToken)->putJson($path, [
            'granted' => true,
            'reason' => '授权该用户发布校园活动任务。',
        ])->assertOk();

        $publisher = TaskPublisher::query()->where('linked_user_id', $user->id)->firstOrFail();
        $proxyId = $publisher->admin_user_id;
        $publisherToken = $this->withToken($userToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertCreated()
            ->assertJsonPath('data.publisher.publisher_type', 'user')
            ->assertJsonPath('data.publisher.admin_display_name', $user->nickname)
            ->assertJsonPath('data.publisher.can_publish', true)
            ->json('data.access_token');

        $pool = $this->withToken($superToken)
            ->withHeaders(['Idempotency-Key' => 'user-publisher-points-pool-01'])
            ->postJson('/api/v1/admin/reward-budget-pools', [
                'name' => '用户发布专项积分池',
                'asset_type' => 'points',
                'total_amount' => 10000,
                'reason' => '初始化用户发布专项测试预算。',
            ])->assertCreated()->json('data');

        $created = $this->withToken($publisherToken)
            ->withHeaders(['Idempotency-Key' => 'authorized-user-task-0001'])
            ->postJson('/api/v1/task-publisher/tasks', $this->taskPayload($pool['id']))
            ->assertCreated()
            ->assertJsonPath('data.publisher.publisher_type', 'user')
            ->assertJsonPath('data.publisher.display_name', $user->nickname);
        $task = Task::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->assertSame($proxyId, $task->created_by_admin_id);

        [$otherUser, $otherUserToken] = $this->phoneLogin('13800138104');
        $this->withToken($superToken)->putJson(
            '/api/v1/admin/user-center/users/'.$otherUser->public_id.'/task-publishing-permission',
            ['granted' => true, 'reason' => '授权另一名用户验证任务所有权隔离。'],
        )->assertOk();
        $otherPublisherToken = $this->withToken($otherUserToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertCreated()->json('data.access_token');
        $this->withToken($otherPublisherToken)->getJson('/api/v1/task-publisher/tasks/'.$task->public_id)
            ->assertNotFound();
        $this->withToken($otherPublisherToken)->getJson('/api/v1/task-publisher/tasks')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->withToken($superToken)->putJson($path, [
            'granted' => false,
            'reason' => '用户申请暂停任务发布权限。',
        ])->assertOk()
            ->assertJsonPath('data.granted', false)
            ->assertJsonPath('data.status', 'revoked');
        $this->assertNotNull(TaskPublisherSession::query()->where('task_publisher_id', $publisher->id)->firstOrFail()->revoked_at);
        $this->assertSame(0, TaskPublisherSession::query()->where('task_publisher_id', $publisher->id)->whereNull('revoked_at')->count());
        $this->withToken($publisherToken)->getJson('/api/v1/task-publisher/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'PUBLISHER_PERMISSION_REVOKED');
        $this->withToken($publisherToken)->postJson('/api/v1/task-publisher/tasks', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'PUBLISHER_PERMISSION_REVOKED');
        $this->withToken($userToken)->getJson('/api/v1/me')->assertJsonPath('data.can_publish_tasks', false);
        $this->withToken($userToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertForbidden()
            ->assertJsonPath('code', 'PUBLISHER_PERMISSION_DENIED');

        $this->withToken($superToken)->putJson($path, [
            'granted' => true,
            'reason' => '复核通过，恢复校园任务发布权限。',
        ])->assertOk()->assertJsonPath('data.publisher_id', $publisher->public_id);
        $regranted = TaskPublisher::query()->where('linked_user_id', $user->id)->firstOrFail();
        $this->assertSame($publisher->id, $regranted->id);
        $this->assertSame($proxyId, $regranted->admin_user_id);

        $newToken = $this->withToken($userToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertCreated()->json('data.access_token');
        $this->withToken($newToken)->getJson('/api/v1/task-publisher/tasks')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $task->public_id);
    }

    public function test_inactive_user_cannot_be_granted_and_disabling_a_granted_user_revokes_effective_access(): void
    {
        [$user, $userToken] = $this->phoneLogin('13800138103');
        [$superToken] = $this->adminLogin('inactive-user-super', 'super_admin');
        $path = '/api/v1/admin/user-center/users/'.$user->public_id.'/task-publishing-permission';

        $user->update(['status' => 'disabled']);
        $this->withToken($superToken)->putJson($path, [
            'granted' => true,
            'reason' => '尝试向停用用户授权发布权限。',
        ])->assertUnprocessable();
        $this->assertFalse($user->fresh()->can_publish_tasks);

        $user->update(['status' => 'active']);
        $this->withToken($superToken)->putJson($path, [
            'granted' => true,
            'reason' => '用户恢复正常后完成授权复核。',
        ])->assertOk();
        $publisherToken = $this->withToken($userToken)->postJson('/api/v1/me/task-publisher-session')
            ->assertCreated()->json('data.access_token');

        $user->update(['status' => 'disabled']);
        $this->withToken($publisherToken)->getJson('/api/v1/task-publisher/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'PUBLISHER_PERMISSION_REVOKED');
    }

    public function test_legacy_platform_admin_publisher_login_still_works(): void
    {
        $admin = AdminUser::query()->create([
            'account' => 'legacy-front-publisher',
            'display_name' => '原后台发布管理员',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'platform_admin')->value('id'));

        $token = $this->postJson('/api/v1/task-publisher/session', [
            'account' => $admin->account,
            'password' => 'AdminPassword12345!',
        ])->assertCreated()
            ->assertJsonPath('data.publisher.publisher_type', 'platform_admin')
            ->assertJsonPath('data.publisher.can_publish', true)
            ->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/task-publisher/profile')
            ->assertOk()
            ->assertJsonPath('data.publisher_type', 'platform_admin');
    }

    /** @return array{User, string} */
    private function phoneLogin(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'publisher-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => $phone,
            'purpose' => 'login',
        ], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => $phone,
            'verification_code' => '246810',
        ], $headers)->assertOk();

        return [
            User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(),
            $login->json('data.access_token'),
        ];
    }

    /** @return array{string, AdminUser} */
    private function adminLogin(string $account, string $role): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$token, $admin];
    }

    /** @return array<string, mixed> */
    private function taskPayload(string $poolId): array
    {
        return [
            'visibility' => 'public',
            'category' => 'campaign',
            'task_type' => 'one_time',
            'participation_mode' => 'claim_and_submit',
            'verification_mode' => 'manual',
            'title' => '用户授权发布测试任务',
            'description' => '提交真实材料后由平台审核。',
            'tags' => ['校园活动'],
            'rewards' => [['type' => 'points', 'points' => 10]],
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $poolId]],
            'reward_quota_total' => 10,
            'completion_window_hours' => 24,
            'submission_limit' => 3,
            'review_deadline_hours' => 48,
            'appeal_deadline_days' => 7,
            'appeal_limit' => 1,
            'claim_starts_at' => now()->subMinute()->toIso8601String(),
            'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(),
            'rules' => [],
        ];
    }
}
