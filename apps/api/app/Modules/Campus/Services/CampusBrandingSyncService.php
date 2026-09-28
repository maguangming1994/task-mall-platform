<?php

namespace App\Modules\Campus\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Media\Services\MediaStorageService;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class CampusBrandingSyncService
{
    private const MANIFEST_VERSION = 1;

    public function __construct(private readonly MediaStorageService $storage) {}

    /**
     * @return array{
     *   total:int,
     *   imported:int,
     *   reused:int,
     *   previously_imported:int,
     *   skipped:int,
     *   pending:int,
     *   failed:int,
     *   dry_run:bool,
     *   errors:array<int, array{index:int,official_school_code:?string,name:?string,message:string}>
     * }
     */
    public function sync(bool $dryRun = false, ?string $manifestPath = null): array
    {
        $manifestPath ??= database_path('data/campus-emblems/manifest.json');
        $manifest = $this->manifest($manifestPath);
        $result = [
            'total' => count($manifest['items']),
            'imported' => 0,
            'reused' => 0,
            'previously_imported' => 0,
            'skipped' => 0,
            'pending' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'errors' => [],
        ];
        $candidates = [];
        $seenCodes = [];

        foreach ($manifest['items'] as $index => $rawItem) {
            try {
                $item = $this->validatedItem($rawItem, $index, dirname($manifestPath));
                if (isset($seenCodes[$item['official_school_code']])) {
                    throw new RuntimeException('清单包含重复的教育部学校代码。');
                }
                $seenCodes[$item['official_school_code']] = true;

                $campus = Campus::query()
                    ->where('official_school_code', $item['official_school_code'])
                    ->first();
                if (! $campus instanceof Campus) {
                    throw new RuntimeException('未找到与清单教育部学校代码匹配的学校。');
                }
                if ($campus->name !== $item['name']) {
                    throw new RuntimeException("学校名称不匹配，数据库记录为“{$campus->name}”。");
                }
                if ($this->previouslyImportedAsset($item['official_school_code']) instanceof MediaAsset) {
                    $result['previously_imported']++;
                    $result['skipped']++;

                    continue;
                }
                if (! $this->brandingIsEmpty($campus)) {
                    $result['skipped']++;

                    continue;
                }

                $candidates[] = ['campus_id' => $campus->id, 'item' => $item];
            } catch (Throwable $exception) {
                $this->recordFailure($result, $index, $rawItem, $exception);
            }
        }

        if ($dryRun) {
            $result['pending'] = count($candidates);

            return $result;
        }
        if ($candidates === []) {
            return $result;
        }

        $admin = $this->syncActor();
        $provider = MediaStorageProvider::query()->where('active', true)->where('enabled', true)->first();
        if (! $provider instanceof MediaStorageProvider) {
            throw new RuntimeException('同步校徽需要一个已启用的当前素材存储服务。');
        }

        foreach ($candidates as $candidate) {
            /** @var array<string, string|null> $item */
            $item = $candidate['item'];
            try {
                $outcome = $this->syncCampus((int) $candidate['campus_id'], $item, $manifest, $admin, $provider);
                if ($outcome === 'previously_imported') {
                    $result['previously_imported']++;
                    $result['skipped']++;
                } else {
                    $result[$outcome]++;
                }
            } catch (Throwable $exception) {
                $this->recordFailure($result, (int) $item['index'], $item, $exception);
            }
        }

        return $result;
    }

    /**
     * @return array{version:int,source:array<string,mixed>,items:array<int,mixed>}
     */
    private function manifest(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("校徽清单不存在或不可读：{$path}");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('校徽清单不是有效的 JSON。', previous: $exception);
        }

        if (
            ! is_array($manifest)
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! is_array($manifest['source'] ?? null)
            || ! is_array($manifest['items'] ?? null)
            || ! array_is_list($manifest['items'])
        ) {
            throw new RuntimeException('校徽清单必须包含 version 1、source 和 items 数组。');
        }

        return [
            'version' => self::MANIFEST_VERSION,
            'source' => $manifest['source'],
            'items' => $manifest['items'],
        ];
    }

    /**
     * @return array{index:int,official_school_code:string,name:string,file:string,path:string,sha256:string,source_name:string,source_url:?string}
     */
    private function validatedItem(mixed $rawItem, int $index, string $manifestDirectory): array
    {
        if (! is_array($rawItem)) {
            throw new RuntimeException('清单条目必须是对象。');
        }

        $code = $this->requiredString($rawItem, 'official_school_code');
        $name = $this->requiredString($rawItem, 'name');
        $file = $this->requiredString($rawItem, 'file');
        $sha256 = strtolower($this->requiredString($rawItem, 'sha256'));
        $sourceName = $this->requiredString($rawItem, 'source_name');
        $sourceUrl = $rawItem['source_url'] ?? null;

        if (preg_match('/^\d{10}$/', $code) !== 1) {
            throw new RuntimeException('official_school_code 必须是 10 位数字。');
        }
        if (mb_strlen($name) > 120) {
            throw new RuntimeException('学校名称不能超过 120 个字符。');
        }
        if (
            basename(str_replace('\\', '/', $file)) !== $file
            || str_contains($file, '/')
            || str_contains($file, '\\')
            || str_contains($file, ':')
            || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['png', 'jpg'], true)
        ) {
            throw new RuntimeException('清单 file 必须是不含目录穿越的纯 PNG 或 JPG 文件名。');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('sha256 必须是 64 位十六进制摘要。');
        }
        if (mb_strlen($sourceName) > 255) {
            throw new RuntimeException('source_name 不能超过 255 个字符。');
        }
        if ($sourceUrl !== null) {
            if (! is_string($sourceUrl) || strlen($sourceUrl) > 2048 || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('source_url 如有填写，必须是有效 URL。');
            }
            $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('source_url 必须使用 HTTP 或 HTTPS。');
            }
        }

        $path = $this->resolvedImagePath($manifestDirectory, $file);
        $actualSha256 = hash_file('sha256', $path);
        if (! is_string($actualSha256) || ! hash_equals($sha256, strtolower($actualSha256))) {
            throw new RuntimeException('校徽图片的 sha256 与清单不一致。');
        }

        return [
            'index' => $index,
            'official_school_code' => $code,
            'name' => $name,
            'file' => $file,
            'path' => $path,
            'sha256' => $sha256,
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
        ];
    }

    /** @param array<string, mixed> $item */
    private function requiredString(array $item, string $field): string
    {
        $value = $item[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("清单条目 {$field} 必须是非空字符串。");
        }

        return trim($value);
    }

    private function resolvedImagePath(string $directory, string $file): string
    {
        $realDirectory = realpath($directory);
        $realPath = realpath($directory.DIRECTORY_SEPARATOR.$file);
        if ($realDirectory === false || $realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('校徽图片不存在或不可读。');
        }

        $base = rtrim(str_replace('\\', '/', $realDirectory), '/').'/';
        $path = str_replace('\\', '/', $realPath);
        if (PHP_OS_FAMILY === 'Windows') {
            $base = strtolower($base);
            $path = strtolower($path);
        }
        if (! str_starts_with($path, $base)) {
            throw new RuntimeException('校徽图片解析到了清单目录之外。');
        }

        return $realPath;
    }

    private function brandingIsEmpty(Campus $campus): bool
    {
        return blank($campus->emblem_asset_id) && blank($campus->emblem_url);
    }

    private function syncActor(): AdminUser
    {
        $superAdmin = AdminUser::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('code', 'super_admin'))
            ->orderBy('id')
            ->first();
        $admin = $superAdmin ?? AdminUser::query()->where('status', 'active')->orderBy('id')->first();
        if (! $admin instanceof AdminUser) {
            throw new RuntimeException('同步校徽需要至少一个启用中的管理员用于记录素材创建人。');
        }

        return $admin;
    }

    /**
     * @param  array<string, string|null>  $item
     * @param  array{version:int,source:array<string,mixed>,items:array<int,mixed>}  $manifest
     * @return 'imported'|'reused'|'previously_imported'|'skipped'
     */
    private function syncCampus(int $campusId, array $item, array $manifest, AdminUser $admin, MediaStorageProvider $provider): string
    {
        return DB::transaction(function () use ($campusId, $item, $manifest, $admin, $provider): string {
            $campus = Campus::query()->lockForUpdate()->findOrFail($campusId);
            if ($this->previouslyImportedAsset($item['official_school_code'], true) instanceof MediaAsset) {
                return 'previously_imported';
            }
            if (! $this->brandingIsEmpty($campus)) {
                return 'skipped';
            }

            $lockedProvider = MediaStorageProvider::query()
                ->whereKey($provider->getKey())
                ->where('active', true)
                ->where('enabled', true)
                ->lockForUpdate()
                ->first();
            if (! $lockedProvider instanceof MediaStorageProvider) {
                throw new RuntimeException('同步过程中当前素材存储服务已发生变化，请重试。');
            }

            $asset = MediaAsset::query()
                ->where('storage_provider', $lockedProvider->provider)
                ->where('sha256', $item['sha256'])
                ->where('status', 'active')
                ->whereIn('mime_type', ['image/png', 'image/jpeg'])
                ->where('source_type', 'bundled_import')
                ->oldest('id')
                ->lockForUpdate()
                ->first();
            $outcome = $asset instanceof MediaAsset ? 'reused' : 'imported';
            if (! $asset instanceof MediaAsset) {
                $asset = $this->storage->storeBundledImage($item['path'], $admin, [
                    'campus_branding' => [
                        'manifest_version' => $manifest['version'],
                        'manifest_source_name' => $this->manifestSourceValue($manifest['source'], 'source_name'),
                        'manifest_source_url' => $this->manifestSourceUrl($manifest['source']),
                        'manifest_license' => $this->manifestSourceValue($manifest['source'], 'license'),
                        'official_school_code' => $item['official_school_code'],
                        'school_name' => $item['name'],
                        'original_file' => $item['file'],
                        'source_name' => $item['source_name'],
                        'source_url' => $item['source_url'],
                        'processed_official_school_codes' => [$item['official_school_code']],
                        'processed_schools' => [$this->processedSchool($item)],
                    ],
                ]);
            } else {
                $this->recordProcessedSchool($asset, $item);
            }

            $campus->update([
                'emblem_asset_id' => $asset->public_id,
                'emblem_url' => $asset->deliveryUrl(),
            ]);

            return $outcome;
        });
    }

    private function previouslyImportedAsset(string $officialSchoolCode, bool $lock = false): ?MediaAsset
    {
        $query = MediaAsset::query()
            ->where('source_type', 'bundled_import')
            ->where(fn ($branding) => $branding
                ->whereJsonContains('metadata->campus_branding->processed_official_school_codes', $officialSchoolCode)
                ->orWhere('metadata->campus_branding->official_school_code', $officialSchoolCode))
            ->oldest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, string|null>  $item
     * @return array<string, string|null>
     */
    private function processedSchool(array $item): array
    {
        return [
            'official_school_code' => $item['official_school_code'],
            'name' => $item['name'],
            'original_file' => $item['file'],
            'source_name' => $item['source_name'],
            'source_url' => $item['source_url'],
        ];
    }

    /** @param array<string, string|null> $item */
    private function recordProcessedSchool(MediaAsset $asset, array $item): void
    {
        $metadata = is_array($asset->metadata) ? $asset->metadata : [];
        $branding = is_array($metadata['campus_branding'] ?? null) ? $metadata['campus_branding'] : [];
        $codes = is_array($branding['processed_official_school_codes'] ?? null)
            ? array_values(array_filter($branding['processed_official_school_codes'], 'is_string'))
            : [];
        $schools = is_array($branding['processed_schools'] ?? null)
            ? array_values(array_filter($branding['processed_schools'], 'is_array'))
            : [];

        if (! in_array($item['official_school_code'], $codes, true)) {
            $codes[] = $item['official_school_code'];
            $schools[] = $this->processedSchool($item);
        }

        $metadata['campus_branding'] = [
            ...$branding,
            'processed_official_school_codes' => $codes,
            'processed_schools' => $schools,
        ];
        $metadata['delivery_url_version'] ??= 1;
        $asset->metadata = $metadata;
        $asset->save();
    }

    /** @param array<string, mixed> $source */
    private function manifestSourceValue(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $source */
    private function manifestSourceUrl(array $source): ?string
    {
        return $this->manifestSourceValue($source, 'source_url')
            ?? $this->manifestSourceValue($source, 'url');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordFailure(array &$result, int $index, mixed $rawItem, Throwable $exception): void
    {
        $result['failed']++;
        $result['errors'][] = [
            'index' => $index,
            'official_school_code' => is_array($rawItem) && is_string($rawItem['official_school_code'] ?? null) ? $rawItem['official_school_code'] : null,
            'name' => is_array($rawItem) && is_string($rawItem['name'] ?? null) ? $rawItem['name'] : null,
            'message' => $exception->getMessage(),
        ];
    }
}
