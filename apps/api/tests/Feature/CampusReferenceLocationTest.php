<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusBrandingUrl;
use App\Modules\Campus\Services\CampusLocationResolver;
use App\Modules\Campus\Services\CampusReferenceLocations;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusReferenceLocationTest extends TestCase
{
    use RefreshDatabase;

    private function school(): Campus
    {
        return Campus::query()->create([
            'official_school_code' => '4136010403', 'code' => 'ncu-test', 'name' => '南昌大学',
            'province' => '江西省', 'city' => '南昌市', 'catalog_status' => 'listed', 'status' => 'active',
        ]);
    }

    public function test_nearest_campus_reference_changes_with_position_and_does_not_write_user_or_campus_coordinates(): void
    {
        $school = $this->school();
        $points = app(CampusReferenceLocations::class)->forCampus($school);
        $this->assertCount(3, $points);
        foreach ($points as $point) {
            $response = $this->postJson('/api/v1/campuses/nearby', [
                'latitude' => $point['latitude'], 'longitude' => $point['longitude'],
            ])->assertOk()->assertJsonPath('data.items.0.location_accuracy', 'reference')
                ->assertJsonPath('data.items.0.location_name', $point['name'])
                ->assertJsonPath('data.items.0.coordinate_system', 'WGS84');
            $this->assertEquals(0, $response->json('data.items.0.distance_km'));
        }
        $this->assertNull($school->fresh()->latitude);
        $this->assertNull($school->fresh()->longitude);
    }

    public function test_manual_coordinates_override_reference_and_renamed_schools_do_not_inherit_a_wrong_location(): void
    {
        $school = $this->school();
        $resolver = app(CampusLocationResolver::class);
        $school->update(['latitude' => 28.61, 'longitude' => 115.81]);
        $this->assertSame('campus', $resolver->resolve($school)['accuracy']);
        $this->assertSame(28.61, $resolver->resolve($school)['latitude']);
        $school->update(['latitude' => null, 'longitude' => null, 'name' => '另一个学校']);
        $this->assertSame('city', $resolver->resolve($school)['accuracy']);
        $school->update(['name' => '南昌大学', 'province' => '其他省份']);
        $this->assertSame([], app(CampusReferenceLocations::class)->forCampus($school));
        $school->update(['province' => '江西省', 'catalog_status' => 'unlisted']);
        $this->assertSame([], app(CampusReferenceLocations::class)->forCampus($school));
    }

    public function test_city_candidates_do_not_hide_known_nearby_campuses_or_expose_fake_distances(): void
    {
        $school = $this->school();
        for ($index = 0; $index < 35; $index++) {
            Campus::query()->create(['code' => 'city-only-'.$index, 'name' => 'City Only '.$index, 'city' => '南昌市', 'status' => 'active']);
        }
        $response = $this->postJson('/api/v1/campuses/nearby', ['latitude' => 28.6829, 'longitude' => 115.8582])
            ->assertOk()->assertJsonPath('data.items.0.id', $school->public_id)
            ->assertJsonPath('data.items.1.location_accuracy', 'city')->assertJsonPath('data.items.1.distance_km', null);
        $this->assertIsNumeric($response->json('data.items.0.distance_km'));
    }

    public function test_reference_snapshot_has_unique_codes_and_consistent_counts_and_coordinates(): void
    {
        $snapshot = json_decode(file_get_contents(database_path('data/campus_reference_locations.json')), true, flags: JSON_THROW_ON_ERROR);
        $official = collect(json_decode(file_get_contents(database_path('data/china_higher_education_institutions_2026.json')), true, flags: JSON_THROW_ON_ERROR)['institutions'])->keyBy('official_school_code');
        $this->assertSame('WGS84', $snapshot['metadata']['coordinate_system']);
        $this->assertCount($snapshot['metadata']['school_count'], array_unique(array_column($snapshot['items'], 'official_school_code')));
        $total = 0;
        $references = app(CampusReferenceLocations::class);
        foreach ($snapshot['items'] as $item) {
            $this->assertSame($official[$item['official_school_code']]['name'], $item['name']);
            $school = new Campus([...$item, 'catalog_status' => 'listed']);
            $points = $references->forCampus($school);
            $this->assertNotEmpty($points);
            $total += count($points);
        }
        $this->assertSame($snapshot['metadata']['point_count'], $total);
    }

    public function test_old_local_http_branding_uses_canonical_domain_without_rewriting_external_images(): void
    {
        config(['app.url' => 'https://rwsc.example.test']);
        $asset = new MediaAsset([
            'public_id' => '01J00000000000000000000001', 'status' => 'active', 'storage_provider' => 'local',
            'object_path' => 'media/2026/school.png', 'public_url' => 'http://111.231.169.12/storage/media/2026/school.png',
        ]);
        $urls = app(CampusBrandingUrl::class);
        $this->assertSame('https://rwsc.example.test/storage/media/2026/school.png', $urls->resolve($asset->public_id, $asset, null));
        $asset->public_url = 'https://cdn.example.test/school.png';
        $this->assertSame($asset->public_url, $urls->resolve($asset->public_id, $asset, null));
        $asset->status = 'inactive';
        $this->assertNull($urls->resolve($asset->public_id, $asset, 'https://stale.example.test/a.png'));
    }

    public function test_account_refresh_does_not_drop_selected_school_branding(): void
    {
        $school = $this->school();
        $school->update(['emblem_url' => 'https://example.test/emblem.png', 'logo_url' => 'https://example.test/logo.png']);
        $user = User::factory()->create(['campus_id' => $school->id]);
        $result = app(AuthService::class)->presentUser($user);
        $this->assertSame($school->emblem_url, $result['campus']['emblem_url']);
        $this->assertSame($school->logo_url, $result['campus']['logo_url']);
    }
}
