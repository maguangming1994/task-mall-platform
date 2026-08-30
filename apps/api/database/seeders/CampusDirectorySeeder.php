<?php

namespace Database\Seeders;

use App\Modules\Campus\Services\CampusDirectorySyncService;
use Illuminate\Database\Seeder;

class CampusDirectorySeeder extends Seeder
{
    public function run(CampusDirectorySyncService $directory): void
    {
        $result = $directory->sync();
        $this->command?->info(sprintf(
            '高校名录已同步：%d 条，新增 %d 条，更新 %d 条，退出名录 %d 条。',
            $result['total'],
            $result['new'],
            $result['changed'],
            $result['unlisted'],
        ));
    }
}
