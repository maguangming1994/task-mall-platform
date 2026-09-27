<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Media\Services\MediaStorageService;
use App\Modules\Upload\Models\UploadedFile as ControlledUploadedFile;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaStorageFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_managed_product_image_to_active_local_storage(): void
    {
        $token = $this->adminToken();
        $response = $this->withToken($token)->post('/api/v1/admin/media/assets/upload', [
            'file' => UploadedFile::fake()->image('product-main.png', 800, 800),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'product-main.png')
            ->assertJsonPath('data.provider', 'local')
            ->assertJsonPath('data.status', 'active');

        $asset = MediaAsset::query()->firstOrFail();
        $this->assertStringContainsString('/storage/media/', $asset->public_url);
        $this->assertSame(1, $asset->metadata['delivery_url_version']);
        $this->assertSame(rtrim((string) config('app.url'), '/').'/api/v1/media/assets/'.$asset->public_id, $response->json('data.url'));
        $this->get('/api/v1/media/assets/'.$asset->public_id)->assertRedirect($asset->public_url);
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'media.asset.upload',
            'subject_type' => 'media_asset',
            'subject_id' => $asset->public_id,
        ]);
        $this->assertTrue(Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->exists($asset->object_path));
        Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->delete($asset->object_path);
    }

    public function test_task_publisher_can_upload_managed_cover_with_publisher_session(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'mobile-publisher-media',
            'display_name' => '移动发布端',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'platform_admin')->value('id'));
        $token = $this->postJson('/api/v1/task-publisher/session', [
            'account' => $admin->account,
            'password' => 'AdminPassword12345!',
        ])->assertCreated()->json('data.access_token');

        $response = $this->withToken($token)->post('/api/v1/task-publisher/media/assets/upload', [
            'file' => UploadedFile::fake()->image('publisher-cover.png', 1200, 800),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.provider', 'local');

        $asset = MediaAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertStringEndsWith('/api/v1/media/assets/'.$asset->public_id, $response->json('data.url'));
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'media.asset.publisher_upload',
            'subject_type' => 'media_asset',
            'subject_id' => $asset->public_id,
        ]);
        Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->delete($asset->object_path);
    }

    public function test_cloud_credentials_are_encrypted_and_never_returned_to_admin(): void
    {
        $token = $this->adminToken('media-secrets');
        $response = $this->withToken($token)->putJson('/api/v1/admin/media/storage/providers/tencent_cos', [
            'enabled' => true,
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com/',
            'region' => ' ap-guangzhou ',
            'bucket' => ' task-mall-1250000000 ',
            'private_bucket' => ' task-mall-private-1250000000 ',
            'public_base_url' => 'https://task-mall-1250000000.cos.ap-guangzhou.myqcloud.com/',
            'access_key' => ' AKID_EXAMPLE_SECRET_VALUE ',
            'secret_key' => ' TENCENT_SECRET_EXAMPLE_VALUE ',
        ])->assertOk();

        $provider = collect($response->json('data'))->firstWhere('provider', 'tencent_cos');
        $this->assertTrue($provider['access_key_configured']);
        $this->assertTrue($provider['secret_key_configured']);
        $this->assertArrayNotHasKey('access_key', $provider);
        $this->assertArrayNotHasKey('secret_key', $provider);
        $raw = (array) \DB::table('media_storage_providers')->where('provider', 'tencent_cos')->first();
        $this->assertNotSame('AKID_EXAMPLE_SECRET_VALUE', $raw['access_key']);
        $this->assertNotSame('TENCENT_SECRET_EXAMPLE_VALUE', $raw['secret_key']);
        $stored = MediaStorageProvider::query()->where('provider', 'tencent_cos')->firstOrFail();
        $this->assertSame('https://cos.ap-guangzhou.myqcloud.com', $stored->endpoint);
        $this->assertSame('ap-guangzhou', $stored->region);
        $this->assertSame('task-mall-1250000000', $stored->bucket);
        $this->assertSame('task-mall-private-1250000000', $stored->private_bucket);
        $this->assertSame('https://task-mall-1250000000.cos.ap-guangzhou.myqcloud.com', $stored->public_base_url);
        $this->assertSame('AKID_EXAMPLE_SECRET_VALUE', $stored->access_key);
        $this->assertSame('TENCENT_SECRET_EXAMPLE_VALUE', $stored->secret_key);
    }

    public function test_cloud_provider_requires_complete_https_configuration_before_it_can_be_enabled(): void
    {
        $token = $this->adminToken('media-cloud-validation');

        $this->withToken($token)->putJson('/api/v1/admin/media/storage/providers/qiniu_kodo', [
            'enabled' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-images',
            'public_base_url' => 'https://images.example.com',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.private_bucket.0', '启用 七牛云 Kodo 前必须填写 private_bucket。');

        $this->withToken($token)->putJson('/api/v1/admin/media/storage/providers/qiniu_kodo', [
            'enabled' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-images',
            'private_bucket' => 'task-mall-private',
            'public_base_url' => 'http://images.example.com',
            'access_key' => 'QINIU_ACCESS_KEY',
            'secret_key' => 'QINIU_SECRET_KEY',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.public_base_url.0', 'public_base_url 必须是不含账号、查询参数和片段的 HTTPS 地址。');

        $this->withToken($token)->putJson('/api/v1/admin/media/storage/providers/qiniu_kodo', [
            'enabled' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-images',
            'private_bucket' => 'task-mall-images',
            'public_base_url' => 'https://images.example.com',
            'access_key' => 'QINIU_ACCESS_KEY',
            'secret_key' => 'QINIU_SECRET_KEY',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.private_bucket.0', '私有文件与公共素材必须使用不同的存储桶或空间。');
    }

    public function test_active_cloud_credentials_are_preflighted_before_the_original_configuration_is_replaced(): void
    {
        $provider = $this->activeQiniuProvider();
        [$publicDisk, $privateDisk] = $this->fakeCloudProbeDisks();
        Storage::shouldReceive('build')->twice()->andReturn($publicDisk, $privateDisk);
        Http::fake(function (ClientRequest $request) use ($publicDisk) {
            if ($request->url() === '' || ! str_starts_with($request->url(), 'https://images.example.test/')) {
                return Http::response('', 403);
            }
            $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

            return Http::response($publicDisk->get($path), 200, ['Content-Type' => 'text/plain']);
        });

        app(MediaStorageService::class)->configure($provider, [
            'enabled' => true,
            'endpoint' => $provider->endpoint,
            'region' => $provider->region,
            'bucket' => $provider->bucket,
            'private_bucket' => $provider->private_bucket,
            'public_base_url' => $provider->public_base_url,
            'access_key' => 'QINIU_ROTATED_ACCESS_KEY',
            'secret_key' => 'QINIU_ROTATED_SECRET_KEY',
        ]);

        $this->assertSame('QINIU_ROTATED_ACCESS_KEY', $provider->fresh()->access_key);
        $this->assertSame('success', $provider->fresh()->last_test_status);
        $this->assertStringContainsString('私有文件不可匿名读取', (string) $provider->fresh()->last_test_message);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/media/health-check/'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/quarantine/health-check/'));
    }

    public function test_failed_active_cloud_preflight_keeps_the_original_credentials(): void
    {
        $provider = $this->activeQiniuProvider();
        [$publicDisk] = $this->fakeCloudProbeDisks();
        Storage::shouldReceive('build')->once()->andReturn($publicDisk);
        Http::fake(fn () => Http::response('', 404));

        try {
            app(MediaStorageService::class)->configure($provider, [
                'enabled' => true,
                'endpoint' => $provider->endpoint,
                'region' => $provider->region,
                'bucket' => $provider->bucket,
                'private_bucket' => $provider->private_bucket,
                'public_base_url' => $provider->public_base_url,
                'access_key' => 'BROKEN_ACCESS_KEY',
                'secret_key' => 'BROKEN_SECRET_KEY',
            ]);
            $this->fail('候选配置测试失败时不应保存。');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('原配置已保留', $exception->getMessage());
        }

        $this->assertSame('QINIU_OLD_ACCESS_KEY', $provider->fresh()->access_key);
        $this->assertSame('QINIU_OLD_SECRET_KEY', $provider->fresh()->secret_key);
    }

    public function test_each_media_consumer_permission_can_upload_shared_media(): void
    {
        foreach (['product.manage', 'task.manage', 'community.review', 'legal.manage', 'privacy.manage', 'settings.manage'] as $index => $permissionCode) {
            $token = $this->adminTokenForPermission('media-role-'.$index, $permissionCode);
            $this->withToken($token)->post('/api/v1/admin/media/assets/upload', [
                'file' => UploadedFile::fake()->image("role-{$index}.png", 32, 32),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app/public')]);
        MediaAsset::query()->get()->each(fn (MediaAsset $asset) => $disk->delete($asset->object_path));
    }

    public function test_private_bucket_cannot_change_while_existing_controlled_files_reference_the_provider(): void
    {
        $provider = MediaStorageProvider::query()->where('provider', 'qiniu_kodo')->firstOrFail();
        $provider->update([
            'enabled' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-public',
            'private_bucket' => 'task-mall-private-old',
            'public_base_url' => 'https://images.example.com',
            'access_key' => 'QINIU_ACCESS_KEY',
            'secret_key' => 'QINIU_SECRET_KEY',
        ]);
        $user = User::query()->create(['nickname' => '存储测试用户', 'status' => 'active']);
        ControlledUploadedFile::query()->create([
            'owner_user_id' => $user->id,
            'purpose' => 'task_submission',
            'original_name' => 'proof.png',
            'extension' => 'png',
            'declared_mime' => 'image/png',
            'declared_size' => 68,
            'disk' => 'qiniu_kodo',
            'quarantine_path' => 'quarantine/test/existing-proof.png',
            'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', 'media-private-bucket-lock'),
            'ticket_expires_at' => now()->addHour(),
        ]);

        $this->withToken($this->adminToken('media-private-bucket-lock'))->putJson('/api/v1/admin/media/storage/providers/qiniu_kodo', [
            'enabled' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-public',
            'private_bucket' => 'task-mall-private-new',
            'public_base_url' => 'https://images.example.com',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.private_bucket.0', '该存储仍有关联的私有文件，不能直接更换私有桶；请先迁移文件或保留原私有桶。');

        $this->assertSame('task-mall-private-old', $provider->fresh()->private_bucket);
    }

    public function test_public_bucket_and_endpoint_cannot_change_while_managed_assets_still_reference_the_provider(): void
    {
        $provider = $this->activeQiniuProvider();
        $admin = AdminUser::query()->create(['account' => 'media-topology-owner', 'display_name' => '素材归属管理员', 'password' => 'AdminPassword12345!']);
        MediaAsset::query()->create([
            'source_type' => 'local_upload', 'original_name' => 'existing.png', 'mime_type' => 'image/png', 'size_bytes' => 68,
            'sha256' => str_repeat('a', 64), 'storage_provider' => 'qiniu_kodo', 'object_path' => 'media/existing.png',
            'public_url' => 'https://images.example.test/media/existing.png', 'status' => 'active', 'metadata' => ['width' => 1, 'height' => 1],
            'created_by_admin_id' => $admin->id,
        ]);

        try {
            app(MediaStorageService::class)->configure($provider, [
                'enabled' => true,
                'endpoint' => $provider->endpoint,
                'region' => $provider->region,
                'bucket' => 'replacement-public-bucket',
                'private_bucket' => $provider->private_bucket,
                'public_base_url' => $provider->public_base_url,
            ]);
            $this->fail('已有素材时不应允许更换公共桶。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bucket', $exception->errors());
        }

        $this->assertSame('task-mall-public', $provider->fresh()->bucket);
    }

    public function test_public_domain_change_atomically_refreshes_existing_asset_targets(): void
    {
        $provider = $this->activeQiniuProvider();
        $admin = AdminUser::query()->create(['account' => 'media-domain-owner', 'display_name' => '域名切换管理员', 'password' => 'AdminPassword12345!']);
        $asset = MediaAsset::query()->create([
            'source_type' => 'local_upload', 'original_name' => 'existing.png', 'mime_type' => 'image/png', 'size_bytes' => 68,
            'sha256' => str_repeat('c', 64), 'storage_provider' => 'qiniu_kodo', 'object_path' => 'media/existing.png',
            'public_url' => 'https://images.example.test/media/existing.png', 'status' => 'active',
            'metadata' => ['width' => 1, 'height' => 1, 'delivery_url_version' => 1], 'created_by_admin_id' => $admin->id,
        ]);
        [$publicDisk, $privateDisk] = $this->fakeCloudProbeDisks();
        Storage::shouldReceive('build')->twice()->andReturn($publicDisk, $privateDisk);
        Http::fake(function (ClientRequest $request) use ($publicDisk) {
            if (! str_starts_with($request->url(), 'https://cdn.example.test/')) {
                return Http::response('', 403);
            }
            $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

            return Http::response($publicDisk->get($path), 200, ['Content-Type' => 'text/plain']);
        });

        app(MediaStorageService::class)->configure($provider, [
            'enabled' => true,
            'endpoint' => $provider->endpoint,
            'region' => $provider->region,
            'bucket' => $provider->bucket,
            'private_bucket' => $provider->private_bucket,
            'public_base_url' => 'https://cdn.example.test',
        ]);

        $this->assertSame('https://cdn.example.test', $provider->fresh()->public_base_url);
        $this->assertSame('https://cdn.example.test/media/existing.png', $asset->fresh()->public_url);
        $this->get('/api/v1/media/assets/'.$asset->public_id)
            ->assertRedirect('https://cdn.example.test/media/existing.png');
    }

    public function test_source_deletion_is_blocked_when_legacy_raw_media_urls_may_still_exist(): void
    {
        $this->adminToken('legacy-media-delete');
        $admin = AdminUser::query()->where('account', 'legacy-media-delete')->firstOrFail();
        MediaAsset::query()->create([
            'source_type' => 'local_upload', 'original_name' => 'legacy.png', 'mime_type' => 'image/png', 'size_bytes' => 68,
            'sha256' => str_repeat('b', 64), 'storage_provider' => 'local', 'object_path' => 'media/legacy.png',
            'public_url' => 'https://old.example.test/storage/media/legacy.png', 'status' => 'active', 'metadata' => ['width' => 1, 'height' => 1],
            'created_by_admin_id' => $admin->id,
        ]);
        MediaStorageProvider::query()->where('provider', 'qiniu_kodo')->update(['enabled' => true]);

        try {
            app(MediaStorageService::class)->startMigration('qiniu_kodo', true, $admin);
            $this->fail('存在历史原始地址时不应删除源文件。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delete_source', $exception->errors());
        }
    }

    public function test_remote_image_is_streamed_from_a_pinned_public_address_and_import_is_audited(): void
    {
        $this->fakePublicDns(['8.8.8.8']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake([
            'https://images.example.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->withToken($this->adminToken('media-import'))->postJson('/api/v1/admin/media/assets/import-url', [
            'url' => 'https://images.example.test/path/source.png?version=1',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'source.png')
            ->assertJsonPath('data.provider', 'local');

        $asset = MediaAsset::query()->where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame('url_import', $asset->source_type);
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'media.asset.import_url',
            'subject_type' => 'media_asset',
            'subject_id' => $asset->public_id,
        ]);
        Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->delete($asset->object_path);
    }

    public function test_remote_import_rejects_private_addresses_and_declared_oversized_images(): void
    {
        $token = $this->adminToken('media-import-security');
        $this->withToken($token)->postJson('/api/v1/admin/media/assets/import-url', [
            'url' => 'https://127.0.0.1/private.png',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.url.0', '图片地址不能指向本机、内网或保留网络。');

        $this->fakePublicDns(['8.8.8.8']);
        Http::fake([
            'https://images.example.test/*' => Http::response('', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) (10 * 1024 * 1024 + 1),
            ]),
        ]);
        $this->withToken($token)->postJson('/api/v1/admin/media/assets/import-url', [
            'url' => 'https://images.example.test/oversized.png',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.url.0', '远程图片不能超过 10MB。');
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_remote_import_rejects_nonstandard_https_ports(): void
    {
        $this->withToken($this->adminToken('media-import-port'))->postJson('/api/v1/admin/media/assets/import-url', [
            'url' => 'https://images.example.test:8443/source.png',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.url.0', '链接转存只允许使用标准 HTTPS 端口。');
    }

    private function adminToken(string $account = 'media-root', string $roleCode = 'super_admin'): string
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');
    }

    private function adminTokenForPermission(string $account, string $permissionCode): string
    {
        $this->seed(RbacSeeder::class);
        $role = Role::query()->create(['code' => $account.'-role', 'name' => $account, 'system_role' => false]);
        $role->permissions()->attach(Permission::query()->where('code', $permissionCode)->value('id'));
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach($role->id);

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');
    }

    private function activeQiniuProvider(): MediaStorageProvider
    {
        MediaStorageProvider::query()->update(['active' => false]);
        $provider = MediaStorageProvider::query()->where('provider', 'qiniu_kodo')->firstOrFail();
        $provider->update([
            'enabled' => true, 'active' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com', 'region' => 'cn-south-1',
            'bucket' => 'task-mall-public', 'private_bucket' => 'task-mall-private',
            'public_base_url' => 'https://images.example.test',
            'access_key' => 'QINIU_OLD_ACCESS_KEY', 'secret_key' => 'QINIU_OLD_SECRET_KEY',
        ]);

        return $provider->fresh();
    }

    /** @return array{0:FilesystemAdapter,1:FilesystemAdapter} */
    private function fakeCloudProbeDisks(): array
    {
        return [Storage::fake('probe-public'), Storage::fake('probe-private')];
    }

    /** @param array<int,string> $addresses */
    private function fakePublicDns(array $addresses): void
    {
        $this->app->instance(MediaStorageService::class, new class($addresses) extends MediaStorageService
        {
            /** @param array<int,string> $addresses */
            public function __construct(private readonly array $addresses) {}

            protected function resolvePublicAddresses(string $host): array
            {
                return $this->addresses;
            }
        });
    }
}
