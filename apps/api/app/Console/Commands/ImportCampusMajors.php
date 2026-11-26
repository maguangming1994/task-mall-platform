<?php

namespace App\Console\Commands;

use App\Modules\Campus\Models\Campus;
use App\Modules\Major\Models\CampusMajor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportCampusMajors extends Command
{
    protected $signature = 'campus:majors:import
                            {--file= : 学校—专业数据文件，默认 database/data/campus_majors.json}
                            {--fresh : 导入前清空 campus_majors 表}
                            {--once : 相同数据文件成功导入后跳过重复执行}
                            {--dry-run : 仅统计匹配情况，不写入数据库}';

    protected $description = '导入学校—专业对照数据（由 scripts/campus-majors/fetch-campus-majors.mjs 生成）';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/campus_majors.json');
        if (! is_file($file)) {
            $this->error("数据文件不存在：{$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        $schools = is_array($payload) ? ($payload['schools'] ?? null) : null;
        if (! is_array($schools) || $schools === []) {
            $this->error('数据文件格式不正确或没有学校数据。');

            return self::FAILURE;
        }

        $digest = hash_file('sha256', $file);
        $marker = $digest !== false ? 'campus-majors-imported:'.$digest : null;
        if ($this->option('once') && ! $this->option('dry-run') && $marker !== null && Cache::get($marker) === true) {
            $this->info('该版本学校—专业数据已经导入成功，跳过。');

            return self::SUCCESS;
        }

        $campusesByCode = Campus::query()
            ->whereNotNull('official_school_code')
            ->get(['id', 'name', 'province', 'official_school_code'])
            ->keyBy(fn (Campus $campus) => (string) $campus->official_school_code);

        $campusesByName = Campus::query()
            ->get(['id', 'name', 'province'])
            ->groupBy('name');

        $rowsByCampus = [];
        $matchedCampuses = 0;
        $unmatched = [];

        foreach ($schools as $school) {
            $campus = $this->resolveCampus($school, $campusesByCode, $campusesByName);
            if (! $campus) {
                $unmatched[] = (string) ($school['name'] ?? '未知学校');

                continue;
            }

            $rows = [];
            $seen = [];
            foreach (($school['majors'] ?? []) as $index => $major) {
                $name = trim((string) ($major['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $level = trim((string) ($major['level'] ?? '')) ?: '本科(普通)';
                $majorName = mb_substr($name, 0, 120);
                $level = mb_substr($level, 0, 32);
                $key = $majorName.'|'.$level;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'campus_id' => $campus->id,
                    'major_name' => $majorName,
                    'major_code' => $this->nullableString($major['code'] ?? null, 20),
                    'category' => $this->nullableString($major['category'] ?? null, 32),
                    'level' => $level,
                    'sort_order' => (int) $index,
                ];
            }

            if ($rows !== []) {
                $rowsByCampus[] = $rows;
                $matchedCampuses += 1;
            }
        }

        $majorCount = array_sum(array_map('count', $rowsByCampus));

        if ($this->option('dry-run')) {
            $this->table(
                ['学校条目', '匹配校区', '未匹配学校', '专业条目', '模式'],
                [[count($schools), $matchedCampuses, count($unmatched), $majorCount, '预检']],
            );

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($rowsByCampus): void {
                if ($this->option('fresh')) {
                    CampusMajor::query()->delete();
                }

                $now = Carbon::now();
                foreach ($rowsByCampus as $rows) {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $chunk = array_map(fn (array $row) => $row + [
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $chunk);

                        CampusMajor::query()->upsert(
                            $chunk,
                            ['campus_id', 'major_name', 'level'],
                            ['major_code', 'category', 'sort_order', 'updated_at'],
                        );
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('导入失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        if ($marker !== null) {
            Cache::forever($marker, true);
        }

        $this->table(
            ['学校条目', '匹配校区', '未匹配学校', '写入专业', '模式'],
            [[count($schools), $matchedCampuses, count($unmatched), $majorCount, $this->option('fresh') ? '已重建' : '已合并']],
        );

        if ($unmatched !== []) {
            $this->warn('以下学校未匹配到校区记录（示例前 10 条）：'.implode('、', array_slice($unmatched, 0, 10)));
        }

        $this->info("数据来源：{$payload['metadata']['source_name']}（抓取时间 {$payload['metadata']['fetched_at']}）");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Campus>  $byCode
     * @param  Collection<string, Collection<int, Campus>>  $byName
     */
    private function resolveCampus(array $school, Collection $byCode, Collection $byName): ?Campus
    {
        $code = (string) ($school['official_school_code'] ?? '');
        if ($code !== '' && $byCode->has($code)) {
            return $byCode->get($code);
        }

        $name = trim((string) ($school['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $candidates = $byName->get($name);
        if (! $candidates instanceof Collection || $candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $province = trim((string) ($school['province'] ?? ''));

        return $candidates->firstWhere('province', $province) ?? $candidates->first();
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
