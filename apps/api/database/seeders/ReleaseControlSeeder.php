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

        // 审核状态全局开关：enabled=审核中（展示审核中文案），disabled=审核过（展示审核过文案）
        FeatureFlag::query()->firstOrCreate(
            ['code' => 'review_in_progress'],
            ['name' => '审核中占位文案', 'mode' => 'disabled', 'rollout_percentage' => 0, 'version' => 1],
        );

        // 功能模块开关：管控已上线模块（审核场景），默认开启，仅可用 disabled/enabled 两态。
        // 每套文案包含「标题」与「描述」，分别用于审核中与审核过两种状态。
        $moduleDefaults = [
            ['code' => 'module_tasks', 'name' => '任务大厅', 'reviewing' => ['功能升级中', '任务大厅正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '任务大厅正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_task_publish', 'name' => '任务发布中心', 'reviewing' => ['功能升级中', '发布中心正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '发布中心正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_mall', 'name' => '商城浏览', 'reviewing' => ['功能升级中', '积分商城正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '积分商城正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_mall_trade', 'name' => '商城交易', 'reviewing' => ['功能升级中', '商城交易正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '商城交易正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_after_sale', 'name' => '售后服务', 'reviewing' => ['功能升级中', '售后服务正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '售后服务正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_community', 'name' => '社区浏览', 'reviewing' => ['功能升级中', '校园社区正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '校园社区正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_community_post', 'name' => '社区发帖', 'reviewing' => ['功能升级中', '发帖功能正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '发帖功能正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_wallet', 'name' => '钱包资产', 'reviewing' => ['功能升级中', '钱包资产正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '钱包资产正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_wallet_recharge', 'name' => '余额充值', 'reviewing' => ['功能升级中', '余额充值正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '余额充值正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_withdraw', 'name' => '提现', 'reviewing' => ['功能升级中', '提现功能正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '提现功能正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_referrals', 'name' => '邀请推荐', 'reviewing' => ['功能升级中', '邀请推荐正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '邀请推荐正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
            ['code' => 'module_checkin', 'name' => '每日签到', 'reviewing' => ['功能升级中', '每日签到正在升级优化，稍作等待，精彩马上回来。'], 'reviewed' => ['服务维护中', '每日签到正在进行服务升级，预计很快恢复，感谢你的耐心等待。']],
        ];

        foreach ($moduleDefaults as $flag) {
            $record = FeatureFlag::query()->firstOrCreate(
                ['code' => $flag['code']],
                [
                    'name' => $flag['name'],
                    'mode' => 'enabled',
                    'rollout_percentage' => 100,
                    'version' => 1,
                    'placeholder_title_reviewing' => $flag['reviewing'][0],
                    'placeholder_desc_reviewing' => $flag['reviewing'][1],
                    'placeholder_title_reviewed' => $flag['reviewed'][0],
                    'placeholder_desc_reviewed' => $flag['reviewed'][1],
                ],
            );
            // 对已存在的记录，仅补全未设置的占位文案，不覆盖管理员已配置的开关状态或文案。
            if ($record->wasRecentlyCreated) {
                continue;
            }
            $fill = [];
            if (! $record->placeholder_title_reviewing) {
                $fill['placeholder_title_reviewing'] = $flag['reviewing'][0];
            }
            if (! $record->placeholder_desc_reviewing) {
                $fill['placeholder_desc_reviewing'] = $flag['reviewing'][1];
            }
            if (! $record->placeholder_title_reviewed) {
                $fill['placeholder_title_reviewed'] = $flag['reviewed'][0];
            }
            if (! $record->placeholder_desc_reviewed) {
                $fill['placeholder_desc_reviewed'] = $flag['reviewed'][1];
            }
            if ($fill !== []) {
                $record->update($fill);
            }
        }
    }
}
