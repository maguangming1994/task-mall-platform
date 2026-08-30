<?php

namespace App\Console\Commands;

use App\Modules\Campus\Services\CampusDirectorySyncService;
use Illuminate\Console\Command;

class SyncCampusDirectory extends Command
{
    protected $signature = 'campuses:sync-directory
                            {--dry-run : 只检查变化，不写入数据库}
                            {--force : 确认执行超出安全阈值的退出名录变更}
                            {--allow-downgrade : 明确允许同步旧于数据库的目录版本}';

    protected $description = '从仓库内已核验的教育部快照同步全国高校目录';

    public function handle(CampusDirectorySyncService $directory): int
    {
        $result = $directory->sync(
            (bool) $this->option('dry-run'),
            (bool) $this->option('force'),
            (bool) $this->option('allow-downgrade'),
        );
        $this->table(
            ['版本', '总数', '新增', '变化', '无变化', '退出名录', '模式'],
            [[
                $result['version'],
                $result['total'],
                $result['new'],
                $result['changed'],
                $result['unchanged'],
                $result['unlisted'],
                $result['dry_run'] ? '预检' : '已写入',
            ]],
        );

        return self::SUCCESS;
    }
}
