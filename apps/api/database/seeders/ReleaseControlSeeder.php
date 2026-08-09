<?php

namespace Database\Seeders;

use App\Modules\Operations\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class ReleaseControlSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'checkout_v2', 'name' => '新版结算流程'],
            ['code' => 'community_video', 'name' => '社区视频内容'],
            ['code' => 'external_notifications', 'name' => '微信与短信外部投递'],
        ] as $flag) {
            FeatureFlag::query()->firstOrCreate(['code' => $flag['code']], $flag + ['mode' => 'disabled', 'rollout_percentage' => 0, 'version' => 1]);
        }
    }
}
