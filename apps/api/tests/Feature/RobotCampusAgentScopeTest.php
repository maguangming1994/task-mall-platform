<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotCampusAgentScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_campus_agent_filter_options_only_return_assigned_campuses(): void
    {
        $own = $this->campus('robot-own-campus', '代理本校');
        $outside = $this->campus('robot-outside-campus', '代理外校');
        [$agent, $token] = $this->campusAgent('robot-scope-agent', $own);

        $ids = collect(
            $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/filter-options')->assertOk()->json('data.schools')
        )->pluck('id')->all();

        $this->assertContains($own->public_id, $ids);
        $this->assertNotContains($outside->public_id, $ids);
    }

    public function test_campus_agent_can_create_forward_for_assigned_campus(): void
    {
        $own = $this->campus('robot-own-campus', '代理本校');
        [$agent, $token] = $this->campusAgent('robot-own-creator', $own);
        $this->verify($token);

        $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $this->forwardPayload($own))
            ->assertCreated()
            ->assertJsonPath('data.filter_rules.campus_id', $own->public_id);
    }

    public function test_campus_agent_cannot_create_forward_for_unassigned_campus(): void
    {
        $own = $this->campus('robot-own-campus', '代理本校');
        $outside = $this->campus('robot-outside-campus', '代理外校');
        [$agent, $token] = $this->campusAgent('robot-outside-creator', $own);
        $this->verify($token);

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/robot/forwards', $this->forwardPayload($outside))
            ->assertStatus(422);

        $this->assertSame(
            ['学校代理只能配置自己负责学校的转发任务。'],
            $response->json('details.errors')['filter_rules.campus_id']
        );
    }

    public function test_campus_agent_cannot_create_post_bot_for_unassigned_campus(): void
    {
        $own = $this->campus('robot-own-campus', '代理本校');
        $outside = $this->campus('robot-outside-campus', '代理外校');
        [$agent, $token] = $this->campusAgent('robot-postbot-agent', $own);
        $this->verify($token);

        $payload = [
            'name' => '活跃度小助手', 'enabled' => true, 'campus_id' => $outside->public_id,
            'category_code' => 'experience', 'content_source' => 'pool', 'posts_per_day' => 3,
            'posts_per_run' => 3, 'active_start' => '08:00', 'active_end' => '22:00',
            'min_gap_minutes' => 60, 'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ];

        $this->withToken($token)->postJson('/api/v1/admin/robot/post-bots', $payload)
            ->assertStatus(422)
            ->assertJsonPath('details.errors.campus_id.0', '学校代理只能管理自己负责学校的发帖机器人。');

        $payload['campus_id'] = $own->public_id;
        $this->withToken($token)->postJson('/api/v1/admin/robot/post-bots', $payload)->assertOk();
    }

    public function test_campus_agent_cannot_update_global_robot_settings(): void
    {
        $own = $this->campus('robot-own-campus', '代理本校');
        [$agent, $token] = $this->campusAgent('robot-settings-agent', $own);
        $this->verify($token);

        $this->withToken($token)->putJson('/api/v1/admin/robot/settings', [
            'base_url' => 'https://robot.example.com',
            'api_key' => 'test-key',
        ])->assertStatus(422)
            ->assertJsonPath('details.errors.settings.0', '学校代理无权修改机器人全局设置。');
    }

    private function campus(string $code, string $name): Campus
    {
        return Campus::query()->create([
            'code' => $code, 'name' => $name, 'status' => 'active',
            'catalog_type' => 'manual', 'catalog_status' => 'manual',
        ]);
    }

    /** @return array{AdminUser, string} */
    private function campusAgent(string $account, Campus $campus): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        $admin->campuses()->attach($campus->id, ['is_primary' => true]);

        return [$admin, $this->login($account)];
    }

    private function login(string $account): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account, 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }

    private function verify(string $token): void
    {
        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function forwardPayload(Campus $campus): array
    {
        return [
            'name' => '校园代理转发',
            'description' => '代理配置的转发',
            'enabled' => true,
            'filter_rules' => [
                'platforms' => ['微信小程序'],
                'campus_id' => $campus->public_id,
                'categories' => [['code' => 'guide', 'name' => '客户端伪造分类']],
                'aggregate_interval' => 30, 'min_messages' => 1, 'max_messages' => 10,
                'quiet_start' => '23:00', 'quiet_end' => '07:00', 'active_weekdays' => [1, 2, 3, 4, 5],
            ],
            'template_config' => [
                'template' => '${index}. ${title}', 'keywords' => ['招聘'], 'content_max_length' => 200,
            ],
            'robot_targets' => [[
                'device_id' => 'device-001', 'device_name' => 'iPhone 快照',
                'wxid' => 'wxid_owner', 'account_name' => '运营微信',
                'groups' => [['group_id' => 'group@chatroom', 'group_name' => '测试群']],
            ]],
        ];
    }
}
