<?php

namespace App\Console\Commands;

use App\Modules\Campus\Services\CampusBrandingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncCampusBranding extends Command
{
    protected $signature = 'campuses:sync-branding
                            {--supplement : 使用全国高校补充校徽包}
                            {--once : 相同清单成功同步后跳过重复运行}
                            {--dry-run : 仅校验并预览，不写入素材或学校记录}';

    protected $description = '从仓库内已校验的校徽包幂等同步学校品牌素材';

    public function handle(CampusBrandingSyncService $branding): int
    {
        $lock = Cache::lock('campus-branding-sync', 900);
        if (! $lock->get()) {
            $this->error('已有校徽同步任务正在执行，请稍后重试。');

            return self::FAILURE;
        }
        try {
            $manifestPath = database_path($this->option('supplement') ? 'data/campus-emblems-supplement/manifest.json' : 'data/campus-emblems/manifest.json');
            $digest = hash_file('sha256', $manifestPath);
            if ($digest === false) {
                throw new \RuntimeException('校徽清单不存在或无法读取。');
            }
            $marker = 'campus-branding-imported:'.$digest;
            if ($this->option('once') && ! $this->option('dry-run') && Cache::get($marker) === true) {
                $this->info('此版本校徽清单已经同步成功。');

                return self::SUCCESS;
            }
            $result = $this->option('supplement')
                ? $branding->sync((bool) $this->option('dry-run'), $manifestPath)
                : $branding->sync((bool) $this->option('dry-run'));
            if ($result['failed'] === 0 && ! $result['dry_run'] && $result['total'] > 0) {
                Cache::forever($marker, true);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->table(
            ['总数', '新增素材', '复用素材', '历史已导入', '跳过', '待导入', '失败', '模式'],
            [[
                $result['total'],
                $result['imported'],
                $result['reused'],
                $result['previously_imported'],
                $result['skipped'],
                $result['pending'],
                $result['failed'],
                $result['dry_run'] ? '预检' : '已写入',
            ]],
        );
        foreach ($result['errors'] as $error) {
            $identity = trim(($error['official_school_code'] ?? '未知代码').' '.($error['name'] ?? ''));
            $this->error("条目 {$error['index']}（{$identity}）：{$error['message']}");
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
