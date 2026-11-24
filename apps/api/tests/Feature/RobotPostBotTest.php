<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Robot\Models\RobotPostBot;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotPostBotTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->adminUser = AdminUser::query()->create([
            'account' => 'post-bot-owner', 'display_name' => '发帖运营', 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
    }

    public function test_pool_bot_publishes_one_post_per_tick_and_rotates_templates(): void
    {
        $campus = $this->campus();
        $bot = $this->bot($campus, ['content_source' => 'pool', 'min_gap_minutes' => 1]);
        $this->template($bot, '今天食堂的新窗口', '一食堂二楼新开了一个窗口，${campus}的同学们去试了吗？味道比想象中好，排队也不久。');
        $this->template($bot, '图书馆自习攻略', '期末临近，${campus}图书馆四楼靠窗的位置最安静，建议早上八点前到，插座也充足。

');

        $this->artisan('robot:post-bots')->assertExitCode(0);

        $post = CommunityPost::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('今天食堂的新窗口', $post->title);
        $this->assertSame('guide', $post->category_code);
        $this->assertSame('published', $post->status);
        $this->assertSame($campus->id, $post->campus_id);
        $author = User::query()->findOrFail($post->user_id);
        $this->assertTrue($author->is_bot);
        $this->assertSame('发布科技大学小助手', $author->nickname);
        $this->assertTrue(CommunityPostVersion::query()->where('community_post_id', $post->id)->exists());

        $bot->refresh();
        $this->assertSame(1, $bot->posted_today);
        $this->assertNotNull($bot->last_posted_at);
        $this->assertSame(1, $bot->templates()->whereNotNull('used_at')->count());

        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(2, CommunityPost::query()->where('category_code', 'guide')->count());
        $this->assertSame(2, $bot->refresh()->templates()->whereNotNull('used_at')->count());
    }

    public function test_bot_publishes_multiple_posts_per_tick_and_respects_daily_limit(): void
    {
        $campus = $this->campus();
        $bot = $this->bot($campus, ['content_source' => 'pool', 'posts_per_day' => 100, 'posts_per_run' => 3, 'min_gap_minutes' => 1]);
        foreach ([1, 2, 3, 4] as $index) {
            $this->template($bot, '批量发帖模板'.$index, '这是用于验证每分钟批量发帖的正文内容，第'.$index.'条，长度满足要求。');
        }

        $this->artisan('robot:post-bots')->assertExitCode(0);

        $this->assertSame(3, CommunityPost::query()->count(), '一次调度发布 3 条');
        $this->assertSame(3, CommunityPost::query()->where('status', 'published')->count(), '批量帖子均自动通过审核上线');
        $this->assertSame(3, CommunityPost::query()->distinct()->count('title'));
        $this->assertSame(3, $bot->refresh()->posted_today);

        $bot->update(['posts_per_day' => 4]);
        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(4, CommunityPost::query()->count(), '每日剩余额度只允许再发 1 条');

        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(4, CommunityPost::query()->count(), '达到每日上限后停止');

        // 模板用完后按最久未使用轮换，不能一直重复同一条模板。
        $bot->update(['posts_per_day' => 100]);
        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $latestTitles = CommunityPost::query()->orderByDesc('id')->limit(3)->pluck('title');
        $this->assertCount(3, $latestTitles->unique(), '同一批次内模板不重复');
    }

    public function test_bot_respects_daily_limit_and_active_window(): void
    {
        $campus = $this->campus();
        $bot = $this->bot($campus, ['content_source' => 'pool', 'posts_per_day' => 1, 'min_gap_minutes' => 1]);
        $this->template($bot, '限量帖子一', '这是发帖机器人用于验证每日上限的第一条正文内容，长度足够。');
        $this->template($bot, '限量帖子二', '这是发帖机器人用于验证每日上限的第二条正文内容，长度足够。');

        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(1, CommunityPost::query()->count(), '达到每日上限后不再发布');

        $bot->update(['posts_per_day' => 5, 'active_start' => '00:00', 'active_end' => '00:01']);
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(1, CommunityPost::query()->count(), '不在活跃时段内不发布');
    }

    public function test_history_bot_reposts_popular_post_and_skips_duplicates(): void
    {
        $campus = $this->campus();
        $author = User::query()->create(['campus_id' => $campus->id, 'nickname' => '原帖作者', 'status' => 'active']);
        CommunityPost::query()->create([
            'campus_id' => $campus->id, 'user_id' => $author->id, 'title' => '校园跑步路线推荐',
            'content' => '围绕操场三圈再绕湖一圈，风景很好，适合傍晚跑步。', 'category_code' => 'guide',
            'status' => 'published', 'published_at' => now()->subDays(10), 'like_count' => 30,
            'comment_count' => 2, 'favorite_count' => 1, 'idempotency_key_hash' => hash('sha256', 'history-source'),
        ]);
        $bot = $this->bot($campus, ['content_source' => 'history', 'min_gap_minutes' => 1, 'history_min_likes' => 10]);

        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(2, CommunityPost::query()->count());
        $this->assertSame('校园跑步路线推荐', CommunityPost::query()->orderByDesc('id')->value('title'));

        $this->travel(2)->minutes();
        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(2, CommunityPost::query()->count(), '同一历史帖子不重复搬运');
        $this->assertNotNull($bot->refresh()->last_error);
    }

    public function test_ai_bot_publishes_generated_post(): void
    {
        config()->set('services.robot_ai', ['base_url' => 'https://ai.example.com/v1', 'api_key' => 'ai-secret', 'model' => 'demo-model']);
        Http::fake([
            'https://ai.example.com/v1/chat/completions' => Http::response(['choices' => [[
                'message' => ['content' => '{"title":"图书馆占座小技巧","content":"期末周图书馆座位紧张，建议先在小程序里查看空位，再错峰前往。"}'],
            ]]]),
        ]);
        $campus = $this->campus();
        $bot = $this->bot($campus, ['content_source' => 'ai', 'min_gap_minutes' => 1]);

        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame('图书馆占座小技巧', CommunityPost::query()->orderByDesc('id')->value('title'));
        $this->assertNull($bot->refresh()->last_error);
    }

    public function test_ai_bot_records_upstream_failure_without_publishing(): void
    {
        config()->set('services.robot_ai', ['base_url' => 'https://ai.example.com/v1', 'api_key' => 'ai-secret', 'model' => 'demo-model']);
        Http::fake(['https://ai.example.com/v1/chat/completions' => Http::response(['error' => 'upstream'], 502)]);
        $bot = $this->bot($this->campus(), ['content_source' => 'ai']);

        $this->artisan('robot:post-bots')->assertExitCode(0);
        $this->assertSame(0, CommunityPost::query()->count());
        $this->assertStringContainsString('AI 返回异常', (string) $bot->refresh()->last_error);
    }

    public function test_admin_can_crud_bot_templates_and_publish_now(): void
    {
        [$admin, $token] = $this->adminWithPermissions('post-bot-admin', ['robot.view', 'robot.settings_manage']);
        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $this->verify($token);
        $campus = $this->campus();

        $created = $this->withToken($token)->postJson('/api/v1/admin/robot/post-bots', [
            'name' => '活跃度小助手', 'enabled' => true, 'campus_id' => $campus->public_id,
            'category_code' => 'experience', 'content_source' => 'pool', 'posts_per_day' => 3,
            'posts_per_run' => 3,
            'active_start' => '08:00', 'active_end' => '22:00', 'min_gap_minutes' => 60,
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ])->assertOk()->json('data');
        $this->assertSame('活跃度小助手', $created['name']);
        $this->assertSame(3, $created['posts_per_day']);
        $this->assertSame(3, $created['posts_per_run']);

        $botId = $created['id'];
        $template = $this->withToken($token)->postJson('/api/v1/admin/robot/post-bots/'.$botId.'/templates', [
            'title' => '模板标题测试', 'content' => '模板正文内容，长度需要超过十个字符才能通过校验。',
        ])->assertOk()->json('data');
        $this->withToken($token)->getJson('/api/v1/admin/robot/post-bots/'.$botId.'/templates')
            ->assertOk()->assertJsonCount(1, 'data.items');

        $this->withToken($token)->postJson('/api/v1/admin/robot/post-bots/'.$botId.'/publish')
            ->assertOk()->assertJsonPath('data.title', '模板标题测试');
        $this->assertSame(1, CommunityPost::query()->count());

        $this->withToken($token)->deleteJson('/api/v1/admin/robot/post-bots/'.$botId.'/templates/'.$template['id'])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/admin/robot/post-bots/'.$botId.'/status', ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->withToken($token)->deleteJson('/api/v1/admin/robot/post-bots/'.$botId)->assertOk();
        $this->assertSoftDeleted('robot_post_bots', ['public_id' => $botId]);
        $this->assertTrue((bool) $admin->id);
    }

    private function campus(): Campus
    {
        return Campus::query()->firstOrCreate(
            ['code' => 'post-bot-campus'],
            ['name' => '发布科技大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function bot(Campus $campus, array $overrides = []): RobotPostBot
    {
        return RobotPostBot::query()->create(array_merge([
            'name' => '发帖测试机器人', 'enabled' => true, 'campus_id' => $campus->id,
            'category_code' => 'guide', 'content_source' => 'pool', 'posts_per_day' => 5,
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7], 'active_start' => '00:00', 'active_end' => '23:59',
            'min_gap_minutes' => 1, 'history_min_likes' => 0,
            'created_by_admin_id' => $this->adminUser->id,
        ], $overrides));
    }

    private function template(RobotPostBot $bot, string $title, string $content): void
    {
        $bot->templates()->create(['title' => $title, 'content' => $content, 'enabled' => true, 'sort' => 0]);
    }

    private function verify(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])->assertOk();
    }

    /** @param array<int, string> $permissions @return array{AdminUser, string} */
    private function adminWithPermissions(string $account, array $permissions): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $role = Role::query()->create(['code' => $account.'-role', 'name' => $account, 'system_role' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $permissions)->pluck('id'));
        $admin->roles()->attach($role->id);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account, 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
