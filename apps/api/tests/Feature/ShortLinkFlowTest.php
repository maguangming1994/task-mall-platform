<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ShortLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_post_reuses_one_short_link_and_redirects_to_h5_detail(): void
    {
        $post = $this->publishedPost();
        config(['app.user_h5_url' => 'https://h5.example.com']);

        $first = ShortLink::ensureFor('community_post', $post->id);
        $second = ShortLink::ensureFor('community_post', $post->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->code, $second->code);
        $this->assertSame(6, strlen($first->code));
        $this->assertSame(1, ShortLink::query()->where('target_type', 'community_post')->where('target_id', $post->id)->count());

        $response = $this->get('/s/'.$first->code);

        $response->assertStatus(302);
        $this->assertSame(
            'https://h5.example.com/#/pages/community-detail/index?id='.$post->public_id,
            $response->headers->get('Location'),
        );
        $this->assertSame(1, $first->fresh()->clicks);
        $this->assertNotNull($first->fresh()->last_clicked_at);
    }

    public function test_unknown_or_malformed_code_returns_not_found(): void
    {
        $this->get('/s/abcde2')->assertNotFound();
        $this->get('/s/abc')->assertNotFound();
        $this->get('/s/ABCDE2')->assertNotFound();
    }

    private function publishedPost(): CommunityPost
    {
        $author = User::factory()->create(['status' => 'active']);

        return CommunityPost::query()->create([
            'user_id' => $author->id,
            'title' => '短链跳转测试帖子',
            'content' => '用于验证短链跳转与点击计数的社区帖子正文。',
            'category_code' => 'experience',
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', 'short-link-post'),
            'published_at' => now(),
        ]);
    }
}
