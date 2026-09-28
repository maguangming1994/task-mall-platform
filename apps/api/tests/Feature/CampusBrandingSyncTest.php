<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusBrandingSyncService;
use App\Modules\Campus\Services\CampusDirectorySyncService;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Media\Services\MediaStorageService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CampusBrandingSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestDirectory = storage_path('framework/testing/campus-branding-'.Str::uuid());
        File::ensureDirectoryExists($this->manifestDirectory);
    }

    protected function tearDown(): void
    {
        if (isset($this->manifestDirectory)) {
            File::deleteDirectory($this->manifestDirectory);
        }
        if (app()->bound('db') && \Schema::hasTable('media_assets')) {
            $storage = app(MediaStorageService::class);
            MediaAsset::query()->where('source_type', 'bundled_import')->get()->each(function (MediaAsset $asset) use ($storage): void {
                $provider = MediaStorageProvider::query()->where('provider', $asset->storage_provider)->first();
                if ($provider instanceof MediaStorageProvider) {
                    $storage->disk($provider)->delete($asset->object_path);
                }
            });
        }

        parent::tearDown();
    }

    public function test_dry_run_validates_and_reports_without_requiring_an_admin_or_writing(): void
    {
        $campus = $this->campus('4111000001', 'Dry Run University');
        $manifest = $this->manifest([
            $this->item($campus, 'dry-run.png'),
        ]);

        $result = app(CampusBrandingSyncService::class)->sync(true, $manifest);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['pending']);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseCount('admin_users', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertNull($campus->fresh()->emblem_asset_id);
        $this->assertNull($campus->fresh()->emblem_url);
    }

    public function test_once_skips_only_a_previously_successful_manifest(): void
    {
        Cache::flush();
        $branding = Mockery::mock(CampusBrandingSyncService::class);
        $branding->shouldReceive('sync')->once()->with(false)->andReturn([
            'total' => 1, 'imported' => 1, 'reused' => 0, 'previously_imported' => 0,
            'skipped' => 0, 'pending' => 0, 'failed' => 0, 'dry_run' => false, 'errors' => [],
        ]);
        $this->app->instance(CampusBrandingSyncService::class, $branding);
        $this->artisan('campuses:sync-branding', ['--once' => true])->assertSuccessful();
        $this->artisan('campuses:sync-branding', ['--once' => true])->assertSuccessful();
    }

    public function test_entire_supplement_matches_the_official_catalog_and_contains_real_unique_images(): void
    {
        app(CampusDirectorySyncService::class)->sync();
        $path = database_path('data/campus-emblems-supplement/manifest.json');
        $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $manifest['asset_commit']);
        $this->assertCount(2103, $manifest['items']);
        $this->assertCount(2103, array_unique(array_column($manifest['items'], 'sha256')));
        foreach ($manifest['items'] as $item) {
            $size = getimagesize(dirname($path).'/'.$item['file']);
            $this->assertNotFalse($size, $item['file']);
            $this->assertGreaterThanOrEqual(32, min($size[0], $size[1]), $item['file']);
        }
        $result = app(CampusBrandingSyncService::class)->sync(true, $path);
        $this->assertSame(0, $result['failed'], json_encode($result['errors']));
        $this->assertSame(2103, $result['pending']);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_jpeg_manifest_files_are_accepted_and_imported_with_actual_mime(): void
    {
        $this->seed(RbacSeeder::class);
        $this->admin('jpeg-branding-super', 'super_admin');
        $campus = $this->campus('4111000099', 'JPEG University');
        File::copy(database_path('data/campus-emblems-supplement/3622000335.jpg'), $this->manifestDirectory.'/emblem.jpg');
        $item = $this->item($campus, 'emblem.jpg');
        $result = app(CampusBrandingSyncService::class)->sync(false, $this->manifest([$item]));
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('image/jpeg', MediaAsset::query()->sole()->mime_type);
    }

    public function test_sync_imports_reuses_skips_and_is_a_no_op_when_repeated(): void
    {
        $this->seed(RbacSeeder::class);
        $fallback = $this->admin('branding-fallback', 'operations_admin');
        $superAdmin = $this->admin('branding-super', 'super_admin');
        $first = $this->campus('4111000002', 'First Brand University');
        $second = $this->campus('4111000003', 'Second Brand University');
        $configured = $this->campus('4111000004', 'Configured University', [
            'emblem_url' => 'https://configured.example.test/emblem.png',
        ]);
        $manifest = $this->manifest([
            $this->item($first, 'shared.png'),
            $this->item($second, 'shared.png'),
            $this->item($configured, 'shared.png'),
        ]);

        $result = app(CampusBrandingSyncService::class)->sync(false, $manifest);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['reused']);
        $this->assertSame(0, $result['previously_imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $asset = MediaAsset::query()->sole();
        $this->assertSame($superAdmin->id, $asset->created_by_admin_id);
        $this->assertNotSame($fallback->id, $asset->created_by_admin_id);
        $this->assertSame('bundled_import', $asset->source_type);
        $this->assertSame('4111000002', $asset->metadata['campus_branding']['official_school_code']);
        $this->assertSame('First Brand University', $asset->metadata['campus_branding']['school_name']);
        $this->assertSame(1, $asset->metadata['campus_branding']['manifest_version']);
        $this->assertSame('https://catalog.example.test/campus-emblems', $asset->metadata['campus_branding']['manifest_source_url']);
        $this->assertSame('shared.png', $asset->metadata['campus_branding']['original_file']);
        $this->assertSame(1, $asset->metadata['delivery_url_version']);
        $this->assertEqualsCanonicalizing(
            ['4111000002', '4111000003'],
            $asset->metadata['campus_branding']['processed_official_school_codes'],
        );
        $this->assertSame($asset->public_id, $first->fresh()->emblem_asset_id);
        $this->assertSame($asset->public_id, $second->fresh()->emblem_asset_id);
        $this->assertSame($asset->deliveryUrl(), $first->fresh()->emblem_url);
        $this->assertSame('https://configured.example.test/emblem.png', $configured->fresh()->emblem_url);

        $repeated = app(CampusBrandingSyncService::class)->sync(false, $manifest);
        $this->assertSame(0, $repeated['imported']);
        $this->assertSame(0, $repeated['reused']);
        $this->assertSame(2, $repeated['previously_imported']);
        $this->assertSame(3, $repeated['skipped']);
        $this->assertSame(0, $repeated['failed']);
        $this->assertDatabaseCount('media_assets', 1);

        $first->update(['emblem_asset_id' => null, 'emblem_url' => null]);
        $afterAdminRemoval = app(CampusBrandingSyncService::class)->sync(false, $manifest);
        $this->assertSame(2, $afterAdminRemoval['previously_imported']);
        $this->assertSame(3, $afterAdminRemoval['skipped']);
        $this->assertNull($first->fresh()->emblem_asset_id);
        $this->assertNull($first->fresh()->emblem_url);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_sync_requires_an_active_admin_only_when_a_write_is_needed(): void
    {
        $campus = $this->campus('4111000005', 'No Admin University');
        $manifest = $this->manifest([$this->item($campus, 'no-admin.png')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('需要至少一个启用中的管理员');

        app(CampusBrandingSyncService::class)->sync(false, $manifest);
    }

    public function test_item_failure_does_not_roll_back_successful_imports(): void
    {
        $this->seed(RbacSeeder::class);
        $this->admin('partial-branding-super', 'super_admin');
        $valid = $this->campus('4111000006', 'Valid University');
        $invalid = $this->campus('4111000007', 'Invalid Checksum University');
        $invalidItem = $this->item($invalid, 'partial.png');
        $invalidItem['sha256'] = str_repeat('0', 64);
        $manifest = $this->manifest([
            $this->item($valid, 'partial.png'),
            $invalidItem,
        ]);

        $result = app(CampusBrandingSyncService::class)->sync(false, $manifest);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('sha256 与清单不一致', $result['errors'][0]['message']);
        $this->assertNotNull($valid->fresh()->emblem_asset_id);
        $this->assertNull($invalid->fresh()->emblem_asset_id);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_manifest_rejects_traversal_and_school_name_mismatches_per_item(): void
    {
        $campus = $this->campus('4111000008', 'Database University Name');
        $validItem = $this->item($campus, 'integrity.png');
        $traversal = $validItem;
        $traversal['file'] = '../outside.png';
        $nameMismatch = $validItem;
        $nameMismatch['name'] = 'Manifest University Name';
        $manifest = $this->manifest([$traversal, $nameMismatch]);

        $result = app(CampusBrandingSyncService::class)->sync(false, $manifest);

        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('不含目录穿越', $result['errors'][0]['message']);
        $this->assertStringContainsString('学校名称不匹配', $result['errors'][1]['message']);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_command_returns_a_failure_exit_code_when_any_item_fails(): void
    {
        $branding = Mockery::mock(CampusBrandingSyncService::class);
        $branding->shouldReceive('sync')->once()->with(true)->andReturn([
            'total' => 1,
            'imported' => 0,
            'reused' => 0,
            'previously_imported' => 0,
            'skipped' => 0,
            'pending' => 0,
            'failed' => 1,
            'dry_run' => true,
            'errors' => [[
                'index' => 0,
                'official_school_code' => '4111000009',
                'name' => 'Broken University',
                'message' => 'checksum mismatch',
            ]],
        ]);
        $this->app->instance(CampusBrandingSyncService::class, $branding);

        $this->artisan('campuses:sync-branding', ['--dry-run' => true])
            ->assertExitCode(1);
    }

    /** @param array<string, mixed> $overrides */
    private function campus(string $officialCode, string $name, array $overrides = []): Campus
    {
        return Campus::query()->create([
            'code' => 'moe-'.$officialCode,
            'official_school_code' => $officialCode,
            'name' => $name,
            'catalog_type' => 'ordinary',
            'catalog_status' => 'listed',
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function admin(string $account, string $roleCode): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $admin;
    }

    /** @return array<string, string> */
    private function item(Campus $campus, string $file): array
    {
        $path = $this->manifestDirectory.DIRECTORY_SEPARATOR.$file;
        if (! File::exists($path)) {
            File::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        }

        return [
            'official_school_code' => (string) $campus->official_school_code,
            'name' => $campus->name,
            'file' => $file,
            'sha256' => hash_file('sha256', $path),
            'source_name' => 'Official university website',
            'source_url' => 'https://university.example.test/identity',
        ];
    }

    /** @param array<int, array<string, string>> $items */
    private function manifest(array $items): string
    {
        $path = $this->manifestDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        File::put($path, json_encode([
            'version' => 1,
            'source' => [
                'description' => 'Curated campus emblems',
                'source_name' => 'Verified campus identity sources',
                'source_url' => 'https://catalog.example.test/campus-emblems',
                'license' => 'Institution-owned marks used for identification only',
            ],
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }
}
