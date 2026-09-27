<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Media\Models\MediaAsset;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampusBrandingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_campus_lists_expose_direct_and_current_managed_brand_urls(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->createAdmin('brand-media-owner', 'super_admin');
        $emblem = $this->createMediaAsset($admin, 'emblem.png', 'https://cdn.example.test/emblem-v1.png');
        $campus = Campus::query()->create([
            'code' => 'brand-public-campus',
            'name' => 'Brand Public Campus',
            'status' => 'active',
            'latitude' => 28.6817,
            'longitude' => 115.8579,
            'emblem_asset_id' => $emblem->public_id,
            'emblem_url' => 'https://cdn.example.test/emblem-stale.png',
            'logo_url' => 'https://static.example.test/wordmark.png',
        ]);
        $emblem->update(['public_url' => 'https://cdn.example.test/emblem-v2.png']);

        $this->getJson('/api/v1/campuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $campus->public_id)
            ->assertJsonPath('data.items.0.emblem_url', 'https://cdn.example.test/emblem-v2.png')
            ->assertJsonPath('data.items.0.logo_url', 'https://static.example.test/wordmark.png');

        $nearby = $this->postJson('/api/v1/campuses/nearby', [
            'latitude' => 28.6817,
            'longitude' => 115.8579,
        ])->assertOk()
            ->assertJsonPath('data.items.0.emblem_url', 'https://cdn.example.test/emblem-v2.png')
            ->assertJsonPath('data.items.0.logo_url', 'https://static.example.test/wordmark.png');

        $this->assertArrayNotHasKey('emblem_asset_id', $nearby->json('data.items.0'));
        $this->assertArrayNotHasKey('logo_asset_id', $nearby->json('data.items.0'));

        $emblem->update(['status' => 'inactive']);
        $this->getJson('/api/v1/campuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.emblem_url', null)
            ->assertJsonPath('data.items.0.logo_url', 'https://static.example.test/wordmark.png');
        $this->withToken($this->login($admin))->getJson("/api/v1/admin/campuses/{$campus->public_id}")
            ->assertOk()
            ->assertJsonPath('data.emblem_asset_id', $emblem->public_id)
            ->assertJsonPath('data.emblem_url', null)
            ->assertJsonPath('data.logo_url', 'https://static.example.test/wordmark.png');
    }

    public function test_admin_can_create_view_update_and_clear_managed_or_remote_branding(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->createAdmin('brand-admin', 'super_admin');
        $token = $this->login($admin);
        $emblem = $this->createMediaAsset($admin, 'managed-emblem.png', 'https://cdn.example.test/managed-emblem.png');
        $logo = $this->createMediaAsset($admin, 'managed-wordmark.png', 'https://cdn.example.test/managed-wordmark.png');

        $campusId = $this->withToken($token)->postJson('/api/v1/admin/campuses', [
            'code' => 'brand-managed-campus',
            'name' => 'Brand Managed Campus',
            'status' => 'active',
            'emblem_asset_id' => $emblem->public_id,
            'emblem_url' => 'https://stale.example.test/emblem.png',
            'logo_asset_id' => $logo->public_id,
            'logo_url' => 'https://stale.example.test/wordmark.png',
        ])->assertCreated()
            ->assertJsonPath('data.emblem_asset_id', $emblem->public_id)
            ->assertJsonPath('data.emblem_url', 'https://cdn.example.test/managed-emblem.png')
            ->assertJsonPath('data.logo_asset_id', $logo->public_id)
            ->assertJsonPath('data.logo_url', 'https://cdn.example.test/managed-wordmark.png')
            ->json('data.id');

        $this->assertDatabaseHas('campuses', [
            'public_id' => $campusId,
            'emblem_asset_id' => $emblem->public_id,
            'emblem_url' => 'https://cdn.example.test/managed-emblem.png',
            'logo_asset_id' => $logo->public_id,
            'logo_url' => 'https://cdn.example.test/managed-wordmark.png',
        ]);

        $this->withToken($token)->getJson("/api/v1/admin/campuses/{$campusId}")
            ->assertOk()
            ->assertJsonPath('data.emblem_asset_id', $emblem->public_id)
            ->assertJsonPath('data.logo_asset_id', $logo->public_id)
            ->assertJsonPath('data.logo_url', 'https://cdn.example.test/managed-wordmark.png');

        $this->withToken($token)->getJson('/api/v1/admin/campuses?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.emblem_asset_id', $emblem->public_id)
            ->assertJsonPath('data.items.0.emblem_url', 'https://cdn.example.test/managed-emblem.png');

        $base = [
            'code' => 'brand-managed-campus',
            'name' => 'Brand Managed Campus',
            'status' => 'active',
        ];
        $this->withToken($token)->putJson("/api/v1/admin/campuses/{$campusId}", [
            ...$base,
            'emblem_url' => 'https://static.example.test/replacement-emblem.png',
            'logo_url' => 'https://static.example.test/replacement-wordmark.png',
        ])->assertOk()
            ->assertJsonPath('data.emblem_asset_id', null)
            ->assertJsonPath('data.emblem_url', 'https://static.example.test/replacement-emblem.png')
            ->assertJsonPath('data.logo_asset_id', null)
            ->assertJsonPath('data.logo_url', 'https://static.example.test/replacement-wordmark.png');

        $this->withToken($token)->putJson("/api/v1/admin/campuses/{$campusId}", [
            ...$base,
            'emblem_asset_id' => null,
            'emblem_url' => null,
            'logo_asset_id' => null,
            'logo_url' => null,
        ])->assertOk()
            ->assertJsonPath('data.emblem_asset_id', null)
            ->assertJsonPath('data.emblem_url', null)
            ->assertJsonPath('data.logo_asset_id', null)
            ->assertJsonPath('data.logo_url', null);
    }

    public function test_admin_rejects_inactive_managed_assets_and_invalid_remote_urls(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->createAdmin('brand-validation-admin', 'super_admin');
        $token = $this->login($admin);
        $inactive = $this->createMediaAsset($admin, 'inactive.png', 'https://cdn.example.test/inactive.png', 'inactive');
        $base = [
            'code' => 'brand-validation-campus',
            'name' => 'Brand Validation Campus',
            'status' => 'active',
        ];

        $this->withToken($token)->postJson('/api/v1/admin/campuses', [
            ...$base,
            'emblem_asset_id' => $inactive->public_id,
            'emblem_url' => 'https://cdn.example.test/inactive.png',
        ])->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['emblem_asset_id']]]);

        $this->withToken($token)->postJson('/api/v1/admin/campuses', [
            ...$base,
            'emblem_url' => 'javascript:alert(1)',
        ])->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['emblem_url']]]);
    }

    public function test_admin_branding_filter_and_summary_track_emblem_completion(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->createAdmin('brand-filter-admin', 'super_admin');
        $token = $this->login($admin);
        $branded = Campus::query()->create([
            'code' => 'branded-campus',
            'name' => 'Branded Campus',
            'status' => 'active',
            'emblem_url' => 'https://static.example.test/branded.png',
        ]);
        $activeAsset = $this->createMediaAsset($admin, 'active-filter.png', 'https://cdn.example.test/active-filter.png');
        $managed = Campus::query()->create([
            'code' => 'managed-brand-campus',
            'name' => 'Managed Brand Campus',
            'status' => 'active',
            'emblem_asset_id' => $activeAsset->public_id,
            'emblem_url' => 'https://cdn.example.test/active-filter.png',
        ]);
        $inactiveAsset = $this->createMediaAsset($admin, 'inactive-filter.png', 'https://cdn.example.test/inactive-filter.png', 'inactive');
        $inactiveManaged = Campus::query()->create([
            'code' => 'inactive-managed-brand-campus',
            'name' => 'Inactive Managed Brand Campus',
            'status' => 'active',
            'emblem_asset_id' => $inactiveAsset->public_id,
            'emblem_url' => 'https://cdn.example.test/inactive-filter.png',
        ]);
        $missingManaged = Campus::query()->create([
            'code' => 'missing-managed-brand-campus',
            'name' => 'Missing Managed Brand Campus',
            'status' => 'active',
            'emblem_asset_id' => (string) Str::ulid(),
            'emblem_url' => 'https://cdn.example.test/missing-filter.png',
        ]);
        Campus::query()->create([
            'code' => 'wordmark-only-campus',
            'name' => 'Wordmark Only Campus',
            'status' => 'active',
            'logo_url' => 'https://static.example.test/wordmark-only.png',
        ]);
        $missing = Campus::query()->create([
            'code' => 'missing-brand-campus',
            'name' => 'Missing Brand Campus',
            'status' => 'active',
        ]);

        $configuredResponse = $this->withToken($token)->getJson('/api/v1/admin/campuses?branding_status=configured&page_size=10')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.summary.branding_configured', 2)
            ->assertJsonPath('data.summary.branding_missing', 4);
        $configuredIds = collect($configuredResponse->json('data.items'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$branded->public_id, $managed->public_id], $configuredIds);

        $response = $this->withToken($token)->getJson('/api/v1/admin/campuses?branding_status=missing&page_size=10')
            ->assertOk()
            ->assertJsonCount(4, 'data.items');
        $missingIds = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($missing->public_id, $missingIds);
        $this->assertContains($inactiveManaged->public_id, $missingIds);
        $this->assertContains($missingManaged->public_id, $missingIds);
    }

    private function createAdmin(string $account, string $role): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));

        return $admin;
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }

    private function createMediaAsset(AdminUser $admin, string $name, string $url, string $status = 'active'): MediaAsset
    {
        return MediaAsset::query()->create([
            'source_type' => 'local_upload',
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => 68,
            'sha256' => hash('sha256', $name),
            'storage_provider' => 'local',
            'object_path' => 'media/campus-branding/'.$name,
            'public_url' => $url,
            'status' => $status,
            'metadata' => ['width' => 256, 'height' => 256, 'delivery_url_version' => 1],
            'created_by_admin_id' => $admin->id,
        ]);
    }
}
