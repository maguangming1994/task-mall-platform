<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Models\RobotPostForwardLog;
use App\Modules\Robot\Services\RobotForwardConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotForwardDispatchTest extends TestCase
{
    use RefreshDatabase;

    private bool $providerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('services.xp.api_key', 'xp-auto-forward-secret');
        Http::fake(fn () => $this->providerFails
            ? Http::response(['code' => 500, 'msg' => 'provider error'], 500)
            : Http::response(['code' => 200, 'msg' => 'success', 'data' => [
                'cmd_id' => 'cmd-auto-forward', 'status' => 'QUEUED', 'queued' => true,
                'resolved_device_id' => 'device-001', 'wxid' => 'wxid_owner',
            ]]));
    }

    public function test_auto_forward_aggregates_new_posts_once_per_group_with_footer(): void
    {
        $admin = $this->admin();
        $campus = $this->campus();
        $user = $this->user($campus);

        $posts = [];
        foreach ([1, 2, 3] as $index) {
            $posts[] = $this->createPost($user, $campus, '自动转发帖子'.$index, now()->subMinutes(5 - $index));
        }

        $config = $this->config($admin, $campus, [
            'aggregate_interval' => 1,
            'min_messages' => 2,
            'max_messages' => 10,
        ]);

        $this->artisan('robot:forward-posts')->assertExitCode(0);

        $records = RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->get();
        $this->assertCount(2, $records, '两个目标群各一条消息');
        $message = (string) $records->first()->message;
        $this->assertStringContainsString('1. 自动转发帖子1', $message);
        $this->assertStringContainsString('3. 自动转发帖子3', $message);
        $this->assertSame(1, substr_count($message, '我是小尾巴'));
        $this->assertSame($posts[2]->id, $config->fresh()->last_forwarded_post_id);
        Http::assertSentCount(2);

        $this->travel(2)->minutes();
        $this->artisan('robot:forward-posts')->assertExitCode(0);
        $this->assertSame(2, RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->count(), '已转发帖子不重复发送');
        Http::assertSentCount(2);

        $this->createPost($user, $campus, '自动转发帖子4', now());
        $fifth = $this->createPost($user, $campus, '自动转发帖子5', now());
        $this->travel(2)->minutes();
        $this->artisan('robot:forward-posts')->assertExitCode(0);

        $latest = RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->orderByDesc('id')->first();
        $this->assertStringContainsString('自动转发帖子4', (string) $latest->message);
        $this->assertStringContainsString('自动转发帖子5', (string) $latest->message);
        $this->assertStringNotContainsString('自动转发帖子1', (string) $latest->message);
        $this->assertSame($fifth->id, $config->fresh()->last_forwarded_post_id);
    }

    public function test_auto_forward_waits_for_minimum_posts_and_skips_quiet_hours(): void
    {
        $admin = $this->admin();
        $campus = $this->campus();
        $user = $this->user($campus);
        $this->createPost($user, $campus, '未达门槛帖子', now()->subMinute());

        $config = $this->config($admin, $campus, [
            'aggregate_interval' => 1,
            'min_messages' => 3,
            'max_messages' => 10,
            'quiet_start' => now()->subHours(2)->format('H:i'),
            'quiet_end' => now()->addHours(2)->format('H:i'),
        ]);

        $this->artisan('robot:forward-posts')->assertExitCode(0);
        $this->assertSame(0, RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->count());
        $this->assertNull($config->fresh()->last_forwarded_post_id);

        foreach ([2, 3] as $index) {
            $this->createPost($user, $campus, '静默期帖子'.$index, now()->subMinutes(4 - $index));
        }
        $this->artisan('robot:forward-posts')->assertExitCode(0);
        $this->assertSame(0, RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->count(), '静默时间内不发送');

        $config->forceFill([
            'filter_rules' => array_merge($config->filter_rules, ['quiet_start' => null, 'quiet_end' => null]),
        ])->save();
        $this->artisan('robot:forward-posts')->assertExitCode(0);

        $message = (string) RobotMessageRequest::query()->where('trigger_type', 'AUTO_FORWARD')->orderByDesc('id')->value('message');
        $this->assertStringContainsString('静默期帖子3', $message);
        $this->assertStringContainsString('1. 未达门槛帖子', $message);
    }

    public function test_auto_forward_records_forward_logs_for_each_post_and_group(): void
    {
        $admin = $this->admin();
        $campus = $this->campus();
        $user = $this->user($campus);
        foreach ([1, 2] as $index) {
            $this->createPost($user, $campus, '写入转发日志帖子'.$index, now()->subMinutes(3 - $index));
        }
        $this->config($admin, $campus, ['aggregate_interval' => 1, 'min_messages' => 1, 'max_messages' => 10]);

        $this->artisan('robot:forward-posts')->assertExitCode(0);

        $logs = RobotPostForwardLog::query()->get();
        $this->assertCount(4, $logs, '2 条帖子 × 2 个群各一条日志');
        $this->assertSame(4, $logs->where('status', 'success')->count());
        $this->assertSame(4, $logs->where('manual', false)->count());
        $this->assertNotNull($logs->first()->message_request_id);
        $this->assertSame(2, $logs->pluck('group_id')->unique()->count());
    }

    public function test_manual_forward_resends_published_post_and_list_shows_forward_summary(): void
    {
        $campus = $this->campus();
        $user = $this->user($campus);
        [$admin, $token] = $this->adminToken($campus);
        $this->config($admin, $campus, ['aggregate_interval' => 1, 'min_messages' => 1, 'max_messages' => 10]);
        $post = $this->createPost($user, $campus, '手动重发帖子', now());

        $this->withToken($token)
            ->postJson('/api/v1/admin/community/posts/'.$post->public_id.'/forward')
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonCount(2, 'data.groups');

        $this->assertSame(2, RobotMessageRequest::query()->where('trigger_type', 'MANUAL_FORWARD')->count());
        $this->assertSame(2, RobotPostForwardLog::query()
            ->where('post_id', $post->id)->where('manual', true)->where('status', 'success')->count());

        $this->withToken($token)
            ->getJson('/api/v1/admin/community/posts?id='.$post->public_id)
            ->assertOk()
            ->assertJsonPath('data.items.0.forward.status', 'success')
            ->assertJsonCount(2, 'data.items.0.forward.groups');
    }

    public function test_manual_forward_rejects_unpublished_post_and_missing_config(): void
    {
        $campus = $this->campus();
        $user = $this->user($campus);
        [, $token] = $this->adminToken($campus);
        $post = $this->createPost($user, $campus, '待审核帖子', now());
        $post->forceFill(['status' => 'pending_review'])->save();

        $this->withToken($token)
            ->postJson('/api/v1/admin/community/posts/'.$post->public_id.'/forward')
            ->assertStatus(422);

        $published = $this->createPost($user, $campus, '无配置可转发帖子', now());
        $this->withToken($token)
            ->postJson('/api/v1/admin/community/posts/'.$published->public_id.'/forward')
            ->assertStatus(422);

        $this->assertSame(0, RobotPostForwardLog::query()->count());
    }

    public function test_manual_forward_records_failed_groups_when_provider_errors(): void
    {
        $this->providerFails = true;
        $campus = $this->campus();
        $user = $this->user($campus);
        [$admin, $token] = $this->adminToken($campus);
        $this->config($admin, $campus, ['aggregate_interval' => 1, 'min_messages' => 1, 'max_messages' => 10]);
        $post = $this->createPost($user, $campus, '转发失败帖子', now());

        $this->withToken($token)
            ->postJson('/api/v1/admin/community/posts/'.$post->public_id.'/forward')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(2, RobotPostForwardLog::query()
            ->where('post_id', $post->id)->whereIn('status', ['failed', 'unknown'])->count());

        $this->withToken($token)
            ->getJson('/api/v1/admin/community/posts?id='.$post->public_id)
            ->assertOk()
            ->assertJsonPath('data.items.0.forward.status', 'failed');
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->firstOrCreate(
            ['account' => 'forward-dispatcher'],
            ['display_name' => '转发调度管理员', 'password' => bcrypt('Demo12345678'), 'status' => 'active', 'requires_mfa' => false],
        );
    }

    /** @return array{0: AdminUser, 1: string} */
    private function adminToken(Campus $campus, string $account = 'forward-auditor'): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
            'requires_mfa' => false,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        $admin->campuses()->attach($campus->id, ['is_primary' => true]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function campus(): Campus
    {
        return Campus::query()->firstOrCreate(
            ['code' => 'auto-forward-campus'],
            ['name' => '自动转发科技大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );
    }

    private function user(Campus $campus): User
    {
        return User::query()->create([
            'campus_id' => $campus->id, 'nickname' => '自动转发同学', 'phone' => '13800005678', 'status' => 'active',
        ]);
    }

    private function createPost(User $user, Campus $campus, string $title, mixed $publishedAt): CommunityPost
    {
        return CommunityPost::query()->create([
            'campus_id' => $campus->id,
            'user_id' => $user->id,
            'title' => $title,
            'content' => '自动转发验收正文：'.$title,
            'category_code' => 'guide',
            'status' => 'published',
            'idempotency_key_hash' => hash('sha256', 'auto-forward-'.$title.uniqid('', true)),
            'published_at' => $publishedAt,
            'like_count' => 1,
            'comment_count' => 0,
            'favorite_count' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function config(AdminUser $admin, Campus $campus, array $overrides): RobotForwardConfig
    {
        $data = app(RobotForwardConfigService::class)->normalize([
            'name' => '自动转发配置',
            'enabled' => true,
            'filter_rules' => array_merge([
                'platforms' => [],
                'campus_id' => $campus->public_id,
                'campus_name' => $campus->name,
                'categories' => [['code' => 'guide', 'name' => '实用攻略']],
                'aggregate_interval' => 1,
                'min_messages' => 1,
                'max_messages' => 10,
                'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            ], $overrides),
            'template_config' => [
                'template' => '${index}. ${title}'."\n".'${wappLink}',
                'footer' => '我是小尾巴',
                'keywords' => [],
                'content_max_length' => 60,
            ],
            'robot_targets' => [[
                'device_id' => 'device-001',
                'device_name' => '自动转发设备',
                'wxid' => 'wxid_owner',
                'account_name' => '自动转发账号',
                'groups' => [
                    ['group_id' => 'group-a@chatroom', 'group_name' => '转发群A'],
                    ['group_id' => 'group-b@chatroom', 'group_name' => '转发群B'],
                ],
            ]],
        ]);

        return RobotForwardConfig::query()->create($data + ['created_by_admin_id' => $admin->id]);
    }
}
