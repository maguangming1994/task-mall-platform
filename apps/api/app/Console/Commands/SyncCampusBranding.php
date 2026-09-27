<?php

namespace App\Console\Commands;

use App\Modules\Campus\Services\CampusBrandingSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncCampusBranding extends Command
{
    protected $signature = 'campuses:sync-branding
                            {--dry-run : 仅校验并预览，不写入素材或学校记录}';

    protected $description = '从仓库内已校验的校徽包幂等同步学校品牌素材';

    public function handle(CampusBrandingSyncService $branding): int
    {
        try {
            $result = $branding->sync((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
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
