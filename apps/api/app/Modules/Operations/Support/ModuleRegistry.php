<?php

namespace App\Modules\Operations\Support;

/**
 * 服务端功能模块注册表（单一事实源）。
 *
 * code 集合、父子链必须与小程序 apps/user-app/src/platform/modules.ts
 * 的 MODULE_REGISTRY 保持同口径，由 tests/Unit/ModuleRegistryTest 守护一致性。
 * 新增模块时只需在此处登记一处，门禁/能力下发/管理矩阵均从本注册表驱动。
 */
class ModuleRegistry
{
    /**
     * @var array<string, array{name: string, parent: ?string, group: string}>
     */
    private const MODULES = [
        'module_tasks' => ['name' => '任务大厅', 'parent' => null, 'group' => 'task'],
        'module_task_publish' => ['name' => '任务发布中心', 'parent' => 'module_tasks', 'group' => 'task'],
        'module_mall' => ['name' => '积分商城', 'parent' => null, 'group' => 'mall'],
        'module_mall_trade' => ['name' => '商城交易', 'parent' => 'module_mall', 'group' => 'mall'],
        'module_after_sale' => ['name' => '售后服务', 'parent' => 'module_mall_trade', 'group' => 'mall'],
        'module_community' => ['name' => '校园社区', 'parent' => null, 'group' => 'community'],
        'module_community_post' => ['name' => '社区发帖', 'parent' => 'module_community', 'group' => 'community'],
        'module_wallet' => ['name' => '钱包资产', 'parent' => null, 'group' => 'wallet'],
        'module_wallet_recharge' => ['name' => '余额充值', 'parent' => 'module_wallet', 'group' => 'wallet'],
        'module_withdraw' => ['name' => '提现', 'parent' => 'module_wallet', 'group' => 'wallet'],
        'module_referrals' => ['name' => '邀请推荐', 'parent' => null, 'group' => 'growth'],
        'module_checkin' => ['name' => '每日签到', 'parent' => null, 'group' => 'growth'],
        'module_second_hand' => ['name' => '校园闲置', 'parent' => null, 'group' => 'second_hand'],
        // 快递代取：校园跑腿接单与结算，独立于任务大厅。
        'module_express_pickup' => ['name' => '快递代取', 'parent' => null, 'group' => 'express'],
        // 资料共享：父级为浏览，投喂与求资料可独立关闭。
        'module_material' => ['name' => '资料共享', 'parent' => null, 'group' => 'material'],
        'module_material_post' => ['name' => '投喂资料', 'parent' => 'module_material', 'group' => 'material'],
        'module_material_request' => ['name' => '求资料', 'parent' => 'module_material', 'group' => 'material'],
        // 以下为暂未上线能力：全局默认关闭，后台开启后热生效。
        'module_membership' => ['name' => '校园 PLUS 会员', 'parent' => null, 'group' => 'membership'],
        'module_follow' => ['name' => '关注作者', 'parent' => 'module_community', 'group' => 'community'],
        'module_identity_verification' => ['name' => '实名认证', 'parent' => null, 'group' => 'membership'],
        'module_feedback' => ['name' => '意见反馈', 'parent' => null, 'group' => 'membership'],
    ];

    /**
     * 后台矩阵分组的顺序与中文标签。
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'task' => '任务协作',
        'mall' => '积分商城',
        'community' => '校园社区',
        'wallet' => '资产钱包',
        'growth' => '增长工具',
        'second_hand' => '校园闲置',
        'express' => '快递代取',
        'material' => '资料共享',
        'membership' => '会员与账户',
    ];

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys(self::MODULES);
    }

    /**
     * @return array<string, array{name: string, parent: ?string, group: string}>
     */
    public function all(): array
    {
        return self::MODULES;
    }

    public function exists(string $code): bool
    {
        return isset(self::MODULES[$code]);
    }

    public function name(string $code): ?string
    {
        return self::MODULES[$code]['name'] ?? null;
    }

    public function group(string $code): ?string
    {
        return self::MODULES[$code]['group'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function groups(): array
    {
        return self::GROUPS;
    }

    public function parent(string $code): ?string
    {
        return self::MODULES[$code]['parent'] ?? null;
    }

    /**
     * 返回从直接父级到根祖先的链路（不含模块自身），父关则子必关时逐级使用。
     *
     * @return list<string>
     */
    public function ancestors(string $code): array
    {
        $chain = [];
        $parent = self::MODULES[$code]['parent'] ?? null;
        $guard = 0;
        while ($parent !== null && $guard < 32) {
            $chain[] = $parent;
            $parent = self::MODULES[$parent]['parent'] ?? null;
            $guard++;
        }

        return $chain;
    }
}
