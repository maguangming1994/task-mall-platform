<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Identity\Services\IdentityVerificationSettingsService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 生产初始化：开启「人工审核」模式的实名认证。
 *
 * 无环境保护，需在生产容器内显式执行：
 *   php artisan db:seed --class=ProductionEnableIdentityVerificationSeeder --force
 * 幂等：已经是 manual + 收集开启 + 人工复核开启时直接跳过。
 * 不注册进 DatabaseSeeder，避免本地/测试建库时被意外打开。
 */
class ProductionEnableIdentityVerificationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(IdentityVerificationSettingsService $settings): void
    {
        $current = $settings->effective();
        if ($current['driver'] === 'manual'
            && $current['collection_enabled'] === true
            && $current['manual_review_enabled'] === true) {
            $this->command?->info('实名认证已是「人工审核」目标状态，跳过。');

            return;
        }

        $actor = AdminUser::query()
            ->whereHas('roles', fn ($query) => $query->where('code', 'super_admin'))
            ->oldest('id')
            ->firstOrFail();

        $settings->update(
            [
                'driver' => 'manual',
                'collection_enabled' => true,
                'manual_review_enabled' => true,
                'aliyun' => [
                    'region' => 'cn-hangzhou',
                    'endpoint' => 'cloudauth.aliyuncs.com',
                    'timeout_seconds' => 8,
                ],
            ],
            [],
            '生产环境启用人工实名认证（初始化 seeder）',
            $actor,
        );

        $this->command?->info('实名认证已切换为人工审核模式并发布。');
    }
}
