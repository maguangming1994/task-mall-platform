<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShareQrCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_community_post_qr_prefers_mini_program_code(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'wx-share-test');
        config()->set('wechat_auth.mini.app_secret', 'share-test-secret');

        $png = "\x89PNG\r\n\x1a\nSHARE-CODE";
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'token-share', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/wxa/getwxacode*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $post = $this->communityPost();

        $response = $this->getJson('/api/v1/share/qrcode?type=community_post&id='.$post->public_id)
            ->assertOk()
            ->assertJsonPath('data.mode', 'mini')
            ->assertJsonPath('data.image_base64', 'data:image/png;base64,'.base64_encode($png));
        $this->assertMatchesRegularExpression('#/s/[a-z2-9]{6}$#', (string) $response->json('data.link'));

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/wxa/getwxacode')
            && $request['path'] === '/pages/community-detail/index?id='.$post->public_id);

        $this->get('/api/v1/share/qrcode/image?type=community_post&id='.$post->public_id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertContent($png);
    }

    public function test_qr_falls_back_to_h5_short_link_when_mini_program_unavailable(): void
    {
        config()->set('wechat_auth.driver', 'local');
        config()->set('app.user_h5_url', 'https://h5.example.com');
        $post = $this->communityPost();

        $response = $this->getJson('/api/v1/share/qrcode?type=community_post&id='.$post->public_id)->assertOk();
        $response->assertJsonPath('data.mode', 'h5')->assertJsonPath('data.image_url', null);
        $link = (string) $response->json('data.link');
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(rtrim((string) config('app.url'), '/'), '#').'/s/[a-z2-9]{6}$#',
            $link
        );
        $this->get('/s/'.substr($link, strrpos($link, '/') + 1))
            ->assertRedirect('https://h5.example.com/#/pages/community-detail/index?id='.$post->public_id);
        $this->get('/api/v1/share/qrcode/image?type=community_post&id='.$post->public_id)->assertNotFound();
    }

    private function communityPost(): CommunityPost
    {
        $campus = Campus::query()->firstOrCreate(
            ['code' => 'share-qr-campus'],
            ['name' => '分享二维码大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );
        $user = User::query()->create(['campus_id' => $campus->id, 'nickname' => '发帖同学', 'phone' => '13800009999', 'status' => 'active']);

        return CommunityPost::query()->create([
            'campus_id' => $campus->id,
            'user_id' => $user->id,
            'title' => '分享二维码测试帖子',
            'content' => '用于验证海报二维码优先使用小程序码。',
            'category_code' => 'guide',
            'status' => 'published',
            'idempotency_key_hash' => hash('sha256', 'share-qr-'.uniqid('', true)),
            'published_at' => now()->subMinute(),
        ]);
    }
}
