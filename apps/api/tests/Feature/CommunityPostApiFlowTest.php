<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityPostApiFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('s3');
    }

    public function test_categories_filtered_pagination_detail_and_published_media_are_real_api_capabilities(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'community-api-reviewer', 'display_name' => '社区审核员', 'password' => 'AdminPassword12345!']);
        $file = $this->cleanCommunityImage($user, 'community-guide.png');
        $service = app(CommunityContentService::class);
        $guide = $service->createPost($user, [
            'title' => '这是一篇实用攻略帖子',
            'content' => '这是一段包含清晰步骤与结果的实用攻略正文，用于验证分类和图片展示。',
            'category_code' => 'guide',
            'image_file_ids' => [$file->public_id],
        ], 'community-api-guide-post');
        $experience = $service->createPost($user, [
            'title' => '这是一篇经验分享帖子',
            'content' => '这是一段满足长度要求的经验分享正文，用于验证分类筛选不会混入其他内容。',
            'category_code' => 'experience',
        ], 'community-api-experience-post');
        $service->reviewPost($admin, $guide, ['decision' => 'approve', 'note' => '攻略审核通过。']);
        $service->reviewPost($admin, $experience, ['decision' => 'approve', 'note' => '经验审核通过。']);

        $this->getJson('/api/v1/community/categories')
            ->assertOk()
            ->assertJsonFragment(['code' => 'guide', 'label' => '实用攻略']);
        $this->getJson('/api/v1/community/posts?category=guide&page=1&page_size=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $guide->public_id)
            ->assertJsonPath('data.items.0.category_label', '实用攻略')
            ->assertJsonPath('data.items.0.attachments.0.file_id', $file->public_id);
        $this->getJson('/api/v1/community/posts?category=unsupported')->assertUnprocessable();
        $this->getJson("/api/v1/community/posts/{$guide->public_id}")
            ->assertOk()
            ->assertJsonPath('data.title', '这是一篇实用攻略帖子');
        $this->get("/api/v1/community/media/{$file->public_id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_owner_projection_edit_retry_delete_and_pending_version_guard_are_enforced(): void
    {
        [$token, $user] = $this->phoneLoginWithCommunityConsent('13800138121');
        [$otherToken] = $this->phoneLoginWithCommunityConsent('13800138122');
        $admin = AdminUser::query()->create(['account' => 'community-owner-reviewer', 'display_name' => '社区审核员', 'password' => 'AdminPassword12345!']);
        $service = app(CommunityContentService::class);
        $post = $service->createPost($user, [
            'title' => '等待审核的本人帖子',
            'content' => '这是一段用于验证我的帖子审核状态和本人操作权限的完整正文内容。',
            'category_code' => 'question',
        ], 'community-owner-post');

        $this->withToken($token)->getJson('/api/v1/community/my-posts')
            ->assertOk()
            ->assertJsonPath('data.items.0.latest_version.review_status', 'pending')
            ->assertJsonPath('data.items.0.can_edit', false)
            ->assertJsonPath('data.items.0.can_delete', true);
        $this->getJson("/api/v1/community/posts/{$post->public_id}")->assertNotFound();

        $post = $service->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '首版审核通过。']);
        $payload = [
            'title' => '修改后等待审核的帖子',
            'content' => '修改后的正文同样满足字数要求，并应立即出现在本人视图的最新版本中。',
            'category_code' => 'showcase',
            'image_file_ids' => [],
        ];
        $this->withToken($otherToken)->putJson("/api/v1/community/posts/{$post->public_id}", $payload)->assertNotFound();
        $edited = $this->withToken($token)->putJson("/api/v1/community/posts/{$post->public_id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.title', '等待审核的本人帖子')
            ->assertJsonPath('data.latest_version.title', '修改后等待审核的帖子')
            ->assertJsonPath('data.latest_version.review_status', 'pending')
            ->assertJsonPath('data.can_edit', false);
        $this->withToken($token)->putJson("/api/v1/community/posts/{$post->public_id}", $payload)
            ->assertUnprocessable();

        $service->reviewPost($admin, $post->fresh(), ['decision' => 'reject', 'reason_code' => 'quality', 'note' => '请补充可验证的结果说明。']);
        $this->withToken($token)->getJson('/api/v1/community/my-posts')
            ->assertOk()
            ->assertJsonPath('data.items.0.latest_version.review_status', 'rejected')
            ->assertJsonPath('data.items.0.latest_version.review_note', '请补充可验证的结果说明。')
            ->assertJsonPath('data.items.0.can_edit', true);
        $this->withToken($token)->deleteJson("/api/v1/community/posts/{$post->public_id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->getJson("/api/v1/community/posts/{$post->public_id}")->assertNotFound();
        $this->assertTrue((bool) $edited->json('data.latest_version'));
    }

    private function cleanCommunityImage(User $user, string $name): UploadedFile
    {
        $path = 'quarantine/community/'.$name;
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Storage::disk('s3')->put($path, $bytes);

        return UploadedFile::query()->create([
            'owner_user_id' => $user->id,
            'purpose' => 'community',
            'original_name' => $name,
            'extension' => 'png',
            'declared_mime' => 'image/png',
            'detected_mime' => 'image/png',
            'declared_size' => strlen($bytes),
            'actual_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'disk' => 's3',
            'quarantine_path' => $path,
            'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', 'community-file-'.$name),
            'ticket_expires_at' => now()->addHour(),
            'uploaded_at' => now(),
            'scanned_at' => now(),
        ]);
    }

    /** @return array{0:string,1:User} */
    private function phoneLoginWithCommunityConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'community-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $user = User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail();
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=community')->assertOk()->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => "community-consent-{$phone}-{$index}", 'X-Client-Platform' => 'h5-browser'])
                ->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])
                ->assertCreated();
        }

        return [$token, $user];
    }
}
