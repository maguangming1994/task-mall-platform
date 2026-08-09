<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Media\Models\MediaAsset;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertTrue(Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->exists($asset->object_path));
        Storage::build(['driver' => 'local', 'root' => storage_path('app/public')])->delete($asset->object_path);
    }

    public function test_cloud_credentials_are_encrypted_and_never_returned_to_admin(): void
    {
        $token = $this->adminToken('media-secrets');
        $response = $this->withToken($token)->putJson('/api/v1/admin/media/storage/providers/tencent_cos', [
            'enabled' => true,
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'region' => 'ap-guangzhou',
            'bucket' => 'task-mall-1250000000',
            'public_base_url' => 'https://task-mall-1250000000.cos.ap-guangzhou.myqcloud.com',
            'access_key' => 'AKID_EXAMPLE_SECRET_VALUE',
            'secret_key' => 'TENCENT_SECRET_EXAMPLE_VALUE',
        ])->assertOk();

        $provider = collect($response->json('data'))->firstWhere('provider', 'tencent_cos');
        $this->assertTrue($provider['access_key_configured']);
        $this->assertTrue($provider['secret_key_configured']);
        $this->assertArrayNotHasKey('access_key', $provider);
        $this->assertArrayNotHasKey('secret_key', $provider);
        $raw = (array) \DB::table('media_storage_providers')->where('provider', 'tencent_cos')->first();
        $this->assertNotSame('AKID_EXAMPLE_SECRET_VALUE', $raw['access_key']);
        $this->assertNotSame('TENCENT_SECRET_EXAMPLE_VALUE', $raw['secret_key']);
    }

    private function adminToken(string $account = 'media-root'): string
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');
    }
}
