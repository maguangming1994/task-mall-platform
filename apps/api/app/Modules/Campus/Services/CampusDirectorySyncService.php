<?php

namespace App\Modules\Campus\Services;

use App\Modules\Campus\Models\Campus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class CampusDirectorySyncService
{
    private const SNAPSHOT_FILE = 'china_higher_education_institutions_2026.json';

    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $metadata = $this->snapshot()['metadata'] ?? null;
        if (! is_array($metadata)) {
            throw new RuntimeException('高校名录缺少来源元数据。');
        }

        return $metadata;
    }

    /** @return array{total:int,new:int,changed:int,unchanged:int,unlisted:int,dry_run:bool,version:string} */
    public function sync(bool $dryRun = false, bool $force = false, bool $allowDowngrade = false): array
    {
        return Cache::lock('campus-directory-sync', 120)->block(
            10,
            fn (): array => $this->syncUnlocked($dryRun, $force, $allowDowngrade),
        );
    }

    /** @return array{total:int,new:int,changed:int,unchanged:int,unlisted:int,dry_run:bool,version:string} */
    private function syncUnlocked(bool $dryRun, bool $force, bool $allowDowngrade): array
    {
        $snapshot = $this->snapshot();
        /** @var array<int, array<string, mixed>> $institutions */
        $institutions = $snapshot['institutions'];
        /** @var array<string, mixed> $metadata */
        $metadata = $snapshot['metadata'];
        $version = (string) $metadata['as_of'];
        $sourceUrl = (string) $metadata['source_page_url'];
        $officialCodes = collect($institutions)->pluck('official_school_code')->all();
        $latestVersion = Campus::query()->whereNotNull('catalog_version')->max('catalog_version');
        if (! $allowDowngrade && is_string($latestVersion) && strcmp($latestVersion, $version) > 0) {
            throw new RuntimeException("数据库高校目录版本 {$latestVersion} 新于待同步版本 {$version}，已拒绝回退。");
        }

        $existing = Campus::query()
            ->whereNotNull('official_school_code')
            ->get()
            ->keyBy('official_school_code');

        $new = 0;
        $changed = 0;
        $unchanged = 0;
        $pending = [];
        $officialFields = [
            'name', 'province', 'city', 'catalog_type', 'education_level',
            'supervising_authority', 'official_note', 'catalog_source_url',
        ];

        foreach ($institutions as $institution) {
            $campus = $existing->get($institution['official_school_code']);
            if (! $campus instanceof Campus) {
                $new++;
                $pending[] = $institution;

                continue;
            }

            $hasChanged = $campus->catalog_status !== 'listed' || $campus->catalog_version !== $version;
            foreach ($officialFields as $field) {
                $expected = $field === 'catalog_source_url' ? $sourceUrl : ($institution[$field] ?? null);
                if ($campus->getAttribute($field) !== $expected) {
                    $hasChanged = true;
                    break;
                }
            }
            if ($hasChanged) {
                $changed++;
                $pending[] = $institution;
            } else {
                $unchanged++;
            }
        }

        $unlistedCodes = $existing
            ->filter(fn (Campus $campus) => $campus->catalog_status === 'listed')
            ->keys()
            ->diff($officialCodes)
            ->values();
        $unlisted = $unlistedCodes->count();
        $result = [
            'total' => count($institutions),
            'new' => $new,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'unlisted' => $unlisted,
            'dry_run' => $dryRun,
            'version' => $version,
        ];

        if ($dryRun) {
            return $result;
        }

        $maximumSafeUnlisted = max(25, (int) floor(max(1, $existing->count()) * 0.05));
        if (! $force && $unlisted > $maximumSafeUnlisted) {
            throw new RuntimeException("本次将有 {$unlisted} 所学校退出名录，超过安全阈值 {$maximumSafeUnlisted}；请先预检并使用 --force 确认。");
        }

        $campusByCode = Campus::query()->get(['code', 'official_school_code'])->keyBy('code');
        foreach ($pending as $institution) {
            if ($existing->has($institution['official_school_code'])) {
                continue;
            }
            $generatedCode = 'moe-'.$institution['official_school_code'];
            $conflict = $campusByCode->get($generatedCode);
            if ($conflict instanceof Campus && $conflict->official_school_code !== $institution['official_school_code']) {
                throw new RuntimeException("学校代码 {$generatedCode} 已被现有学校占用，请先完成人工归并。");
            }
        }

        $now = now();
        DB::transaction(function () use ($pending, $unlistedCodes, $existing, $version, $sourceUrl, $now): void {
            foreach ($unlistedCodes->chunk(300) as $codes) {
                Campus::query()
                    ->whereIn('official_school_code', $codes->all())
                    ->where('catalog_status', 'listed')
                    ->update(['catalog_status' => 'unlisted', 'catalog_synced_at' => $now, 'updated_at' => $now]);
            }

            foreach (array_chunk($pending, 300) as $chunk) {
                $rows = array_map(function (array $institution) use ($existing, $version, $sourceUrl, $now): array {
                    /** @var Campus|null $campus */
                    $campus = $existing->get($institution['official_school_code']);

                    return [
                        'public_id' => $campus?->public_id ?? (string) Str::ulid(),
                        'code' => $campus?->code ?? 'moe-'.$institution['official_school_code'],
                        'official_school_code' => $institution['official_school_code'],
                        'name' => $institution['name'],
                        'catalog_type' => $institution['catalog_type'],
                        'education_level' => $institution['education_level'],
                        'supervising_authority' => $institution['supervising_authority'],
                        'official_note' => $institution['official_note'],
                        'catalog_status' => 'listed',
                        'catalog_version' => $version,
                        'catalog_source_url' => $sourceUrl,
                        'catalog_synced_at' => $now,
                        'province' => $institution['province'],
                        'city' => $institution['city'],
                        'district' => $campus?->district,
                        'address' => $campus?->address,
                        'latitude' => $campus?->latitude,
                        'longitude' => $campus?->longitude,
                        'status' => $campus?->status ?? 'active',
                        'sort_order' => $campus?->sort_order ?? 5000,
                        'created_at' => $campus?->created_at ?? $now,
                        'updated_at' => $now,
                    ];
                }, $chunk);

                DB::table('campuses')->upsert(
                    $rows,
                    ['official_school_code'],
                    [
                        'name', 'catalog_type', 'education_level', 'supervising_authority',
                        'official_note', 'catalog_status', 'catalog_version', 'catalog_source_url',
                        'catalog_synced_at', 'province', 'city', 'updated_at',
                    ],
                );
            }
        });

        return $result;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $path = database_path('data/'.self::SNAPSHOT_FILE);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('高校名录数据文件不存在。');
        }

        try {
            $snapshot = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('高校名录数据文件无法解析。', previous: $exception);
        }

        if (! is_array($snapshot) || ! isset($snapshot['metadata'], $snapshot['institutions']) || ! is_array($snapshot['institutions'])) {
            throw new RuntimeException('高校名录数据结构无效。');
        }

        $expectedTotal = (int) ($snapshot['metadata']['counts']['total'] ?? 0);
        $codes = collect($snapshot['institutions'])->pluck('official_school_code');
        $this->validateSnapshot($snapshot, $expectedTotal, $codes->unique()->count());

        return $this->snapshot = $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSnapshot(array $snapshot, int $expectedTotal, int $uniqueCodeCount): void
    {
        $metadata = $snapshot['metadata'] ?? null;
        $institutions = $snapshot['institutions'] ?? null;
        if (! is_array($metadata) || ! is_array($institutions)) {
            throw new RuntimeException('高校名录元数据或记录结构无效。');
        }

        $counts = $metadata['counts'] ?? null;
        $sourceUrl = $metadata['source_page_url'] ?? null;
        $asOf = $metadata['as_of'] ?? null;
        $publishedOn = $metadata['published_on'] ?? null;
        $files = $metadata['files'] ?? null;
        if (
            ! is_array($counts)
            || ! is_string($sourceUrl)
            || ! str_starts_with($sourceUrl, 'https://www.moe.gov.cn/')
            || ! is_string($asOf)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1
            || ! is_string($publishedOn)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedOn) !== 1
            || ($metadata['excludes_hong_kong_macao_taiwan'] ?? null) !== true
            || ! is_array($files)
        ) {
            throw new RuntimeException('高校名录来源、版本或分项统计无效。');
        }

        $actual = [
            'total' => count($institutions),
            'ordinary' => 0,
            'adult' => 0,
            'undergraduate' => 0,
            'vocational' => 0,
        ];
        foreach ($institutions as $index => $institution) {
            if (! is_array($institution)) {
                throw new RuntimeException('高校名录包含无效记录。');
            }

            $code = $institution['official_school_code'] ?? null;
            $name = $institution['name'] ?? null;
            $province = $institution['province'] ?? null;
            $type = $institution['catalog_type'] ?? null;
            $level = $institution['education_level'] ?? null;
            $authority = $institution['supervising_authority'] ?? null;
            if (
                ! is_string($code) || preg_match('/^\d{10}$/', $code) !== 1
                || ! is_string($name) || $name === '' || mb_strlen($name) > 120
                || ! is_string($province) || $province === '' || mb_strlen($province) > 60
                || ! in_array($type, ['ordinary', 'adult'], true)
                || ! is_string($level) || ! in_array($level, ['本科', '专科', '成人高等学校'], true)
                || ! is_string($authority) || $authority === '' || mb_strlen($authority) > 120
            ) {
                throw new RuntimeException('高校名录第 '.($index + 1).' 条记录字段校验失败。');
            }

            foreach (['city' => 60, 'official_note' => 255] as $field => $maxLength) {
                $value = $institution[$field] ?? null;
                if ($value !== null && (! is_string($value) || mb_strlen($value) > $maxLength)) {
                    throw new RuntimeException('高校名录第 '.($index + 1)." 条记录的 {$field} 字段无效。");
                }
            }
            if (($type === 'ordinary' && $level === '成人高等学校') || ($type === 'adult' && $level !== '成人高等学校')) {
                throw new RuntimeException('高校名录类别与办学层次不一致。');
            }

            $actual[$type]++;
            if ($level === '本科') {
                $actual['undergraduate']++;
            } elseif ($level === '专科') {
                $actual['vocational']++;
            }
        }

        if (
            $expectedTotal < 3000
            || $expectedTotal !== $actual['total']
            || $uniqueCodeCount !== $expectedTotal
            || collect($actual)->contains(fn (int $value, string $key) => (int) ($counts[$key] ?? -1) !== $value)
        ) {
            throw new RuntimeException('高校名录记录数、分类统计或学校标识码校验失败。');
        }

        $verifiedFiles = [];
        foreach ($files as $file) {
            $type = is_array($file) ? ($file['catalog_type'] ?? null) : null;
            $url = is_array($file) ? ($file['url'] ?? null) : null;
            $sha256 = is_array($file) ? ($file['sha256'] ?? null) : null;
            $records = is_array($file) ? ($file['records'] ?? null) : null;
            if (
                ! in_array($type, ['ordinary', 'adult'], true)
                || isset($verifiedFiles[$type])
                || ! is_string($url)
                || ! str_starts_with($url, 'https://www.moe.gov.cn/')
                || ! str_ends_with($url, '.xls')
                || ! is_string($sha256)
                || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
                || ! is_int($records)
                || $records !== $actual[$type]
            ) {
                throw new RuntimeException('高校名录官方附件元数据校验失败。');
            }
            $verifiedFiles[$type] = true;
        }
        if (count($verifiedFiles) !== 2) {
            throw new RuntimeException('高校名录官方附件不完整。');
        }
    }
}
