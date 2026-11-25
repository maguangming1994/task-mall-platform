<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Models\RobotPostForwardLog;
use App\Modules\Robot\Services\RobotForwardConfigService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotTaskForwardTest extends TestCase
{
    use RefreshDatabase;

    private bool $providerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('services.xp.api_key', 'xp-task-forward-secret');
        Http::fake(fn () => $this->providerFails
            ? Http::response(['code' => 500, 'msg' => 'provider error'], 500)
            : Http::response(['code' => 200, 'msg' => 'success', 'data' => [
                'cmd_id' => 'cmd-task-forward', 'status' => 'QUEUED', 'queued' => true,
                'resolved_device_id' => 'device-001', 'wxid' => 'wxid_owner',
            ]]));
    }

    public function test_manual_forward_task_renders_reward_link_and_records_logs(): void
    {
        $campus = $this->campus();
        $admin = $this->admin();
        [, $token] = $this->adminToken($campus);
        $this->config($admin, $campus, ['aggregate_interval' => 1, 'min_messages' => 1, 'max_messages' => 10]);
        $task = $this->createTask($admin, $campus, '手动转发任务', now());

        $this->withToken($token)
            ->postJson('/api/v1/admin/tasks/'.$task->public_id.'/forward')
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonCount(2, 'data.groups');

        $records = RobotMessageRequest::query()->where('trigger_type', 'MANUAL_FORWARD')->get();
        $this->assertCount(2, $records, '两个目标群各一条消息');
        $message = (string) $records->first()->message;
        $this->assertStringContainsString('1. 手动转发任务', $message);
        $this->assertStringContainsString('+¥5.00 余额', $message);
        $this->assertStringContainsString('/#/pages/task-detail/index', $message);

        $logs = RobotPostForwardLog::query()->where('post_id', $task->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('task', $logs->first()->content_type);
        $this->assertSame(2, $logs->where('manual', true)->where('status', 'success')->count());
    }

    public function test_manual_forward_task_rejects_unpublished_and_missing_config(): void
    {
        $campus = $this->campus();
        $admin = $this->admin();
        [, $token] = $this->adminToken($campus);
        $draft = $this->createTask($admin, $campus, '草稿任务', now());
        $draft->forceFill(['status' => 'draft'])->save();

        $this->withToken($token)
            ->postJson('/api/v1/admin/tasks/'.$draft->public_id.'/forward')
            ->assertStatus(422);

        $published = $this->createTask($admin, $campus, '无配置任务', now());
        $this->withToken($token)
            ->postJson('/api/v1/admin/tasks/'.$published->public_id.'/forward')
            ->assertStatus(422);

        $this->assertSame(0, RobotPostForwardLog::query()->count());
    }

    public function test_auto_forward_aggregates_new_tasks_and_advances_cursor(): void
    {
        $admin = $this->admin();
        $campus = $this->campus();
        foreach ([1, 2] as $index) {
            $this->createTask($admin, $campus, '自动转发任务'.$index, now()->subMinutes(3 - $index));
        }
        $config = $this->config($admin, $campus, ['aggregate_interval' => 1, 'min_messages' => 1, 'max_messages' => 10]);

        $this->artisan('robot:forward-posts')->assertExitCode(0);

        $records = RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->get();
        $this->assertCount(2, $records, '两个目标群各一条消息');
        $message = (string) $records->first()->message;
        $this->assertStringContainsString('自动转发任务1', $message);
        $this->assertStringContainsString('自动转发任务2', $message);
        $this->assertNotNull($config->fresh()->last_forwarded_post_id);

        $logs = RobotPostForwardLog::query()->get();
        $this->assertCount(4, $logs, '2 条任务 × 2 个群');
        $this->assertSame(4, $logs->where('content_type', 'task')->count());

        $this->travel(2)->minutes();
        $this->artisan('robot:forward-posts')->assertExitCode(0);
        $this->assertSame(2, RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->count(), '已转发任务不重复发送');
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->firstOrCreate(
            ['account' => 'task-forward-dispatcher'],
            ['display_name' => '任务转发管理员', 'password' => bcrypt('Demo12345678'), 'status' => 'active', 'requires_mfa' => false],
        );
    }

    /** @return array{0: AdminUser, 1: string} */
    private function adminToken(Campus $campus): array
    {
        $admin = AdminUser::query()->create([
            'account' => 'task-forward-auditor',
            'display_name' => '任务转发审核员',
            'password' => 'AdminPassword12345!',
            'status' => 'active',
            'requires_mfa' => false,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        $admin->campuses()->attach($campus->id, ['is_primary' => true]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'task-forward-auditor',
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function campus(): Campus
    {
        return Campus::query()->firstOrCreate(
            ['code' => 'task-forward-campus'],
            ['name' => '任务转发科技大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );
    }

    private function createTask(AdminUser $admin, Campus $campus, string $title, mixed $publishedAt): Task
    {
        $task = Task::query()->create([
            'campus_id' => $campus->id,
            'created_by_admin_id' => $admin->id,
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', 'task-forward-'.$title.uniqid('', true)),
            'published_at' => $publishedAt,
        ]);
        TaskVersion::query()->create([
            'task_id' => $task->id,
            'version_no' => 1,
            'status' => 'published',
            'change_type' => 'initial',
            'changed_fields' => [],
            'content' => [
                'title' => $title,
                'description' => '任务转发验收正文：'.$title,
                'category' => 'daily',
                'publisher_display_name' => '校园任务官',
                'rewards' => [['type' => 'wallet_balance', 'balance_amount' => 500]],
            ],
            'created_by_admin_id' => $admin->id,
            'published_at' => $publishedAt,
        ]);

        return $task;
    }

    /** @param array<string, mixed> $overrides */
    private function config(AdminUser $admin, Campus $campus, array $overrides): RobotForwardConfig
    {
        $data = app(RobotForwardConfigService::class)->normalize([
            'name' => '任务转发配置',
            'enabled' => true,
            'filter_rules' => array_merge([
                'content_type' => 'task',
                'platforms' => [],
                'campus_id' => $campus->public_id,
                'campus_name' => $campus->name,
                'categories' => [['code' => 'daily', 'name' => '日常任务']],
                'task_statuses' => ['published'],
                'aggregate_interval' => 1,
                'min_messages' => 1,
                'max_messages' => 10,
                'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            ], $overrides),
            'template_config' => [
                'template' => '${index}. ${title}｜${reward}'."\n".'${wappLink}',
                'footer' => '我是小尾巴',
                'keywords' => [],
                'content_max_length' => 60,
            ],
            'robot_targets' => [[
                'device_id' => 'device-001',
                'device_name' => '任务转发设备',
                'wxid' => 'wxid_owner',
                'account_name' => '任务转发账号',
                'groups' => [
                    ['group_id' => 'group-a@chatroom', 'group_name' => '转发群A'],
                    ['group_id' => 'group-b@chatroom', 'group_name' => '转发群B'],
                ],
            ]],
        ]);

        return RobotForwardConfig::query()->create($data + ['created_by_admin_id' => $admin->id]);
    }
}
