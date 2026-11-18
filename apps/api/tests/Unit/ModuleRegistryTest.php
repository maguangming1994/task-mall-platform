<?php

namespace Tests\Unit;

use App\Modules\Operations\Support\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * 守护服务端注册表与小程序 platform/modules.ts 的 MODULE_REGISTRY 同口径。
 */
class ModuleRegistryTest extends TestCase
{
    public function test_registry_contains_exactly_the_20_module_codes(): void
    {
        $registry = new ModuleRegistry;

        $this->assertSame([
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
            'module_material',
            'module_material_post',
            'module_material_request',
            'module_membership',
            'module_follow',
            'module_identity_verification',
            'module_feedback',
        ], $registry->codes());

        $this->assertCount(20, $registry->all());
    }

    public function test_parent_chain_matches_frontend_registry(): void
    {
        $registry = new ModuleRegistry;

        $expectedParents = [
            'module_tasks' => null,
            'module_task_publish' => 'module_tasks',
            'module_mall' => null,
            'module_mall_trade' => 'module_mall',
            'module_after_sale' => 'module_mall_trade',
            'module_community' => null,
            'module_community_post' => 'module_community',
            'module_wallet' => null,
            'module_wallet_recharge' => 'module_wallet',
            'module_withdraw' => 'module_wallet',
            'module_referrals' => null,
            'module_checkin' => null,
            'module_second_hand' => null,
            'module_material' => null,
            'module_material_post' => 'module_material',
            'module_material_request' => 'module_material',
            'module_membership' => null,
            'module_follow' => null,
            'module_identity_verification' => null,
            'module_feedback' => null,
        ];

        foreach ($expectedParents as $code => $parent) {
            $this->assertSame($parent, $registry->parent($code), "{$code} 的父模块与前端注册表不一致");
        }
    }

    public function test_ancestors_walks_to_root(): void
    {
        $registry = new ModuleRegistry;

        $this->assertSame([], $registry->ancestors('module_mall'));
        $this->assertSame(['module_mall'], $registry->ancestors('module_mall_trade'));
        $this->assertSame(['module_mall_trade', 'module_mall'], $registry->ancestors('module_after_sale'));
        $this->assertSame(['module_community'], $registry->ancestors('module_community_post'));
    }

    public function test_names_and_groups_are_present_for_every_module(): void
    {
        $registry = new ModuleRegistry;

        foreach ($registry->codes() as $code) {
            $this->assertNotSame('', (string) $registry->name($code), "{$code} 缺少中文名");
            $this->assertArrayHasKey($registry->group($code), $registry->groups(), "{$code} 的分组未登记");
        }
    }

    public function test_unknown_code_is_reported_as_missing(): void
    {
        $registry = new ModuleRegistry;

        $this->assertFalse($registry->exists('module_unknown'));
        $this->assertNull($registry->name('module_unknown'));
    }
}
