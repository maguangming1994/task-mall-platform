<?php

namespace Tests\Feature;

use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusDirectorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampusDirectorySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_directory_sync_imports_the_verified_nationwide_snapshot(): void
    {
        $result = app(CampusDirectorySyncService::class)->sync();

        $this->assertSame(3196, $result['total']);
        $this->assertSame(3196, $result['new']);
        $this->assertDatabaseCount('campuses', 3196);
        $this->assertSame(2952, Campus::query()->where('catalog_type', 'ordinary')->count());
        $this->assertSame(244, Campus::query()->where('catalog_type', 'adult')->count());
        $this->assertSame(1412, Campus::query()->where('education_level', '本科')->count());
        $this->assertSame(1540, Campus::query()->where('education_level', '专科')->count());
        $this->assertSame(3196, Campus::query()->where('status', 'active')->count());
        $this->assertDatabaseHas('campuses', [
            'official_school_code' => '4111010001',
            'code' => 'moe-4111010001',
            'name' => '北京大学',
            'province' => '北京市',
            'city' => '北京市',
            'education_level' => '本科',
            'supervising_authority' => '教育部',
            'catalog_status' => 'listed',
            'catalog_version' => '2026-06-17',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/campuses?keyword='.urlencode('北京大学'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'moe-4111010001')
            ->assertJsonPath('data.items.0.location_accuracy', 'city');
    }

    public function test_nearby_directory_endpoint_returns_a_public_school_with_distance(): void
    {
        app(CampusDirectorySyncService::class)->sync();

        $response = $this->postJson('/api/v1/campuses/nearby', [
            'keyword' => '北京大学',
            'latitude' => 39.9042,
            'longitude' => 116.4074,
            'limit' => 10,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'moe-4111010001')
            ->assertJsonPath('data.items.0.location_accuracy', 'city');

        $this->assertNull($response->json('data.items.0.distance_km'));
    }

    public function test_directory_sync_is_idempotent_and_preserves_operational_fields(): void
    {
        $directory = app(CampusDirectorySyncService::class);
        $directory->sync();
        $campus = Campus::query()->where('official_school_code', '4111010001')->firstOrFail();
        $publicId = $campus->public_id;
        $campus->update([
            'name' => '待官方目录纠正的名称',
            'status' => 'disabled',
            'sort_order' => 8,
            'district' => '海淀区',
            'address' => '人工维护地址',
            'latitude' => 39.992,
            'longitude' => 116.304,
        ]);
        Campus::query()->create([
            'code' => 'retired-official-campus',
            'official_school_code' => '4999999999',
            'name' => '历史学校',
            'catalog_type' => 'ordinary',
            'catalog_status' => 'listed',
            'catalog_version' => '2025-06-20',
            'status' => 'active',
        ]);

        $result = $directory->sync();

        $this->assertSame(0, $result['new']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(3195, $result['unchanged']);
        $this->assertSame(1, $result['unlisted']);
        $campus->refresh();
        $this->assertSame($publicId, $campus->public_id);
        $this->assertSame('北京大学', $campus->name);
        $this->assertSame('disabled', $campus->status);
        $this->assertSame(8, $campus->sort_order);
        $this->assertSame('海淀区', $campus->district);
        $this->assertSame('人工维护地址', $campus->address);
        $this->assertSame(39.992, $campus->latitude);
        $this->assertDatabaseHas('campuses', [
            'official_school_code' => '4999999999',
            'catalog_status' => 'unlisted',
            'status' => 'active',
        ]);

        $preservedUpdatedAt = now()->subDay()->startOfSecond();
        DB::table('campuses')->where('id', $campus->id)->update(['updated_at' => $preservedUpdatedAt]);
        $third = $directory->sync();
        $this->assertSame(0, $third['new']);
        $this->assertSame(0, $third['changed']);
        $this->assertSame(3196, $third['unchanged']);
        $this->assertSame(0, $third['unlisted']);
        $this->assertTrue($campus->fresh()->updated_at->equalTo($preservedUpdatedAt));
    }

    public function test_directory_dry_run_never_writes_and_reserved_code_conflicts_fail_closed(): void
    {
        $directory = app(CampusDirectorySyncService::class);

        $preview = $directory->sync(true);
        $this->assertSame(3196, $preview['new']);
        $this->assertTrue($preview['dry_run']);
        $this->assertDatabaseCount('campuses', 0);

        Campus::query()->create([
            'code' => 'moe-4111010001',
            'name' => '人工占用代码学校',
            'catalog_type' => 'manual',
            'catalog_status' => 'manual',
            'status' => 'disabled',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('请先完成人工归并');
        $directory->sync();
    }

    public function test_activation_migration_only_enables_disabled_listed_official_schools(): void
    {
        $syncedAt = now()->subDay()->startOfSecond();
        $listed = Campus::query()->create([
            'code' => 'listed-disabled',
            'official_school_code' => '4111000001',
            'name' => '待激活高校',
            'catalog_type' => 'ordinary',
            'catalog_status' => 'listed',
            'status' => 'disabled',
            'catalog_synced_at' => $syncedAt,
        ]);
        $manuallyDisabled = Campus::query()->create([
            'code' => 'listed-manually-disabled',
            'official_school_code' => '4111000002',
            'name' => '后台人工停用高校',
            'catalog_type' => 'ordinary',
            'catalog_status' => 'listed',
            'status' => 'disabled',
            'catalog_synced_at' => $syncedAt,
        ]);
        $manual = Campus::query()->create([
            'code' => 'manual-disabled',
            'name' => '人工停用学校',
            'catalog_status' => 'manual',
            'status' => 'disabled',
        ]);
        DB::table('campuses')->where('id', $listed->id)->update([
            'catalog_synced_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ]);
        DB::table('campuses')->where('id', $manuallyDisabled->id)->update([
            'catalog_synced_at' => $syncedAt,
            'updated_at' => $syncedAt->copy()->addHour(),
        ]);

        $migration = require database_path('migrations/2026_08_30_020000_activate_listed_official_campuses.php');
        $migration->up();

        $this->assertSame('active', $listed->fresh()->status);
        $this->assertSame('disabled', $manuallyDisabled->fresh()->status);
        $this->assertSame('disabled', $manual->fresh()->status);
    }

    public function test_city_centroids_cover_every_official_school_city_and_reject_same_name_mismatches(): void
    {
        $directory = json_decode(file_get_contents(database_path('data/china_higher_education_institutions_2026.json')), true, flags: JSON_THROW_ON_ERROR);
        $locations = json_decode(file_get_contents(database_path('data/china_city_centroids.json')), true, flags: JSON_THROW_ON_ERROR);
        $cities = $locations['cities'];
        $schoolCities = collect($directory['institutions'])->pluck('city')->filter();
        $missing = $schoolCities->unique()->diff(array_keys($cities))->values()->all();

        $this->assertSame('WGS84', $locations['metadata']['coordinate_system']);
        $this->assertSame(346, $locations['metadata']['city_count']);
        $this->assertSame(346, count($cities));
        $this->assertSame(2952, $locations['metadata']['school_count_covered']);
        $this->assertSame(2952, $schoolCities->count());
        $this->assertSame([], $missing);

        foreach ([
            '大同市' => [39.5, 40.5, 112.5, 114.5],
            '中山市' => [22.0, 23.0, 112.5, 114.5],
            '资阳市' => [29.5, 30.8, 103.5, 105.5],
            '宁波市' => [29.0, 30.5, 120.5, 122.5],
            '南阳市' => [32.0, 34.0, 111.5, 113.5],
            '襄阳市' => [31.0, 33.0, 111.0, 113.5],
        ] as $city => [$minLat, $maxLat, $minLng, $maxLng]) {
            $this->assertGreaterThanOrEqual($minLat, $cities[$city]['latitude'], $city.' 纬度过小');
            $this->assertLessThanOrEqual($maxLat, $cities[$city]['latitude'], $city.' 纬度过大');
            $this->assertGreaterThanOrEqual($minLng, $cities[$city]['longitude'], $city.' 经度过小');
            $this->assertLessThanOrEqual($maxLng, $cities[$city]['longitude'], $city.' 经度过大');
        }
    }

    public function test_public_school_selector_excludes_unlisted_official_schools(): void
    {
        Campus::query()->create([
            'code' => 'listed-campus',
            'official_school_code' => '4111000001',
            'name' => '仍在名录学校',
            'catalog_type' => 'ordinary',
            'catalog_status' => 'listed',
            'status' => 'active',
        ]);
        $unlisted = Campus::query()->create([
            'code' => 'unlisted-campus',
            'official_school_code' => '4111000002',
            'name' => '已退出名录学校',
            'catalog_type' => 'ordinary',
            'catalog_status' => 'unlisted',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/campuses')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'listed-campus');

        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'campus-directory-test'];
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138991',
            'purpose' => 'login',
        ], $headers)->assertAccepted();
        $token = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138991',
            'verification_code' => '246810',
        ], $headers)->assertOk()->json('data.access_token');

        $this->withToken($token)->putJson('/api/v1/me/campus', ['campus_id' => $unlisted->public_id])
            ->assertUnprocessable();

        $this->withToken($token)->putJson('/api/v1/me/campus', ['campus_id' => Campus::query()->where('code', 'listed-campus')->value('public_id')])
            ->assertOk()
            ->assertJsonPath('data.code', 'listed-campus');
    }
}
