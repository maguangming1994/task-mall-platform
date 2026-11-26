<?php

namespace App\Modules\Operations\Support;

/**
 * 校区分期上线套餐（阶段模板）。
 *
 * 语义约定：
 * - basic/standard：套餐内模块显式 enabled，其余 17 模块显式 disabled。
 *   必须显式关闭而非继承——全局默认 enabled 时 inherit 即开放。
 * - full：不写任何覆盖行（全部 inherit 跟随全局，含平台灰度与应急总闸门），
 *   应用时删除该校区全部覆盖行。
 */
class ModulePackage
{
    public const BASIC = 'basic';

    public const STANDARD = 'standard';

    public const FULL = 'full';

    /**
     * @var array<string, array{name: string, description: string, modules: list<string>}>
     */
    private const PACKAGES = [
        self::BASIC => [
            'name' => '基础版',
            'description' => '任务大厅、校园社区、每日签到。适合首批学校冷启动。',
            'modules' => [
                'module_tasks',
                'module_community',
                'module_checkin',
            ],
        ],
        self::STANDARD => [
            'name' => '标准版',
            'description' => '基础版 + 校园闲置、积分商城浏览。交易/发布/钱包等能力暂不开放。',
            'modules' => [
                'module_tasks',
                'module_community',
                'module_checkin',
                'module_second_hand',
                'module_mall',
            ],
        ],
        self::FULL => [
            'name' => '完整版',
            'description' => '全部 17 个模块跟随平台全局配置开放。',
            'modules' => [
                'module_tasks',
                'module_task_publish',
                'module_mall',
                'module_mall_trade',
                'module_after_sale',
                'module_community',
                'module_community_post',
                'module_wallet',
                'module_wallet_recharge',
                'module_withdraw',
                'module_referrals',
                'module_checkin',
                'module_second_hand',
                'module_express_pickup',
                'module_membership',
                'module_follow',
                'module_identity_verification',
                'module_feedback',
            ],
        ],
    ];

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys(self::PACKAGES);
    }

    public function exists(string $code): bool
    {
        return isset(self::PACKAGES[$code]);
    }

    public function name(string $code): ?string
    {
        return self::PACKAGES[$code]['name'] ?? null;
    }

    /**
     * 套餐目录（供后台矩阵展示）。
     *
     * @return array<string, array{name: string, description: string, modules: list<string>}>
     */
    public function catalog(): array
    {
        return self::PACKAGES;
    }

    /**
     * 套餐对全部 17 个模块的目标覆盖状态（code => enabled/disabled）。
     * 完整版返回空数组，语义为“不保留任何覆盖行，全部 inherit”。
     *
     * @return array<string, string>
     */
    public function targetStatuses(string $package): array
    {
        if (! isset(self::PACKAGES[$package])) {
            throw new \InvalidArgumentException("Unknown module package: {$package}");
        }
        if ($package === self::FULL) {
            return [];
        }

        $enabled = array_flip(self::PACKAGES[$package]['modules']);
        $statuses = [];
        foreach ($this->registry->codes() as $code) {
            $statuses[$code] = isset($enabled[$code]) ? 'enabled' : 'disabled';
        }

        return $statuses;
    }
}
