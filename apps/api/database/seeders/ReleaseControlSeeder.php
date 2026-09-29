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

        // 功能模块开关：管控已上线模块（审核场景），默认开启，仅可用 disabled/enabled 两态。
        foreach ([
            ['code' => 'module_tasks', 'name' => '任务大厅'],
            ['code' => 'module_task_publish', 'name' => '任务发布中心'],
            ['code' => 'module_mall', 'name' => '商城浏览'],
            ['code' => 'module_mall_trade', 'name' => '商城交易'],
            ['code' => 'module_after_sale', 'name' => '售后服务'],
            ['code' => 'module_community', 'name' => '社区浏览'],
            ['code' => 'module_community_post', 'name' => '社区发帖'],
            ['code' => 'module_wallet', 'name' => '钱包资产'],
            ['code' => 'module_wallet_recharge', 'name' => '余额充值'],
            ['code' => 'module_withdraw', 'name' => '提现'],
            ['code' => 'module_referrals', 'name' => '邀请推荐'],
            ['code' => 'module_checkin', 'name' => '每日签到'],
        ] as $flag) {
            FeatureFlag::query()->firstOrCreate(['code' => $flag['code']], $flag + ['mode' => 'enabled', 'rollout_percentage' => 100, 'version' => 1]);
        }
    }
}
