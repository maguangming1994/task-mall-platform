<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['code' => 'user.view_masked', 'name' => '查看脱敏用户', 'group' => 'user'],
            ['code' => 'user.view_sensitive', 'name' => '查看完整敏感资料', 'group' => 'user'],
            ['code' => 'identity.resolve', 'name' => '处理身份合并冲突', 'group' => 'user'],
            ['code' => 'identity.review', 'name' => '审核实名认证', 'group' => 'user'],
            ['code' => 'privacy.manage', 'name' => '处理隐私请求与账号注销', 'group' => 'user'],
            ['code' => 'legal.manage', 'name' => '管理协议版本与发布', 'group' => 'system'],
            ['code' => 'task.view', 'name' => '查看任务', 'group' => 'task'],
            ['code' => 'task.manage', 'name' => '管理任务', 'group' => 'task'],
            ['code' => 'task.review', 'name' => '审核任务提交', 'group' => 'task'],
            ['code' => 'task.frontend_publish', 'name' => '用户端发布任务', 'group' => 'task'],
            ['code' => 'push.view', 'name' => '查看地推运营', 'group' => 'push'],
            ['code' => 'push.team_manage', 'name' => '管理地推团队', 'group' => 'push'],
            ['code' => 'push.member_manage', 'name' => '管理地推成员与等级', 'group' => 'push'],
            ['code' => 'push.rule_manage', 'name' => '配置地推佣金规则', 'group' => 'push'],
            ['code' => 'push.task_manage', 'name' => '配置地推推广任务', 'group' => 'push'],
            ['code' => 'push.review', 'name' => '审核地推申请与交单', 'group' => 'push'],
            ['code' => 'push.settle', 'name' => '管理地推结算批次', 'group' => 'push'],
            ['code' => 'robot.view', 'name' => '查看 XP 机器人', 'group' => 'robot'],
            ['code' => 'robot.settings_manage', 'name' => '配置 XP 机器人', 'group' => 'robot'],
            ['code' => 'robot.message_send', 'name' => '发送 XP 群消息', 'group' => 'robot'],
            ['code' => 'community.review', 'name' => '审核社区内容', 'group' => 'community'],
            ['code' => 'material.manage', 'name' => '管理资料共享', 'group' => 'material'],
            ['code' => 'product.manage', 'name' => '管理商品与优惠券', 'group' => 'mall'],
            ['code' => 'fulfillment.manage', 'name' => '管理库存与履约', 'group' => 'mall'],
            ['code' => 'after_sale.review', 'name' => '审核售后', 'group' => 'after_sale'],
            ['code' => 'finance.manage', 'name' => '管理支付与对账', 'group' => 'finance'],
            ['code' => 'operations.manage', 'name' => '管理通知与运营', 'group' => 'operations'],
            ['code' => 'campus.manage', 'name' => '管理学校与代理', 'group' => 'campus'],
            ['code' => 'campus.module_manage', 'name' => '配置校区功能模块与套餐', 'group' => 'campus'],
            ['code' => 'deployment.manage', 'name' => '管理发布、灰度与回滚', 'group' => 'system'],
            ['code' => 'settings.manage', 'name' => '管理系统配置', 'group' => 'system'],
            ['code' => 'settings.approve', 'name' => '审核高风险系统配置', 'group' => 'system'],
            ['code' => 'rbac.manage', 'name' => '管理角色权限', 'group' => 'system'],
            ['code' => 'audit.view', 'name' => '查看全局审计', 'group' => 'audit'],
            ['code' => 'audit.view_own', 'name' => '查看本人审计', 'group' => 'audit'],
        ];

        foreach ($permissions as $attributes) {
            Permission::query()->updateOrCreate(['code' => $attributes['code']], $attributes);
        }

        $rolePermissions = [
            'super_admin' => array_column($permissions, 'code'),
            'platform_admin' => ['user.view_masked', 'identity.review', 'privacy.manage', 'legal.manage', 'task.view', 'task.manage', 'task.frontend_publish', 'push.view', 'push.review', 'audit.view_own'],
            'operations_admin' => ['user.view_masked', 'task.view', 'task.manage', 'product.manage', 'operations.manage', 'material.manage', 'push.view', 'push.team_manage', 'push.member_manage', 'push.rule_manage', 'push.task_manage', 'push.review', 'robot.view', 'robot.settings_manage', 'audit.view_own'],
            'campus_agent' => ['user.view_masked', 'identity.review', 'task.view', 'task.manage', 'task.review', 'community.review', 'material.manage', 'product.manage', 'campus.manage', 'push.view', 'push.team_manage', 'push.member_manage', 'push.task_manage', 'push.review', 'robot.view', 'robot.settings_manage', 'audit.view_own'],
            'task_reviewer' => ['user.view_masked', 'task.view', 'task.review', 'after_sale.review', 'push.view', 'push.review', 'audit.view_own'],
            'community_reviewer' => ['user.view_masked', 'community.review', 'audit.view_own'],
            'customer_service' => ['user.view_masked', 'identity.review', 'privacy.manage', 'task.view', 'after_sale.review', 'push.view', 'audit.view_own'],
            'warehouse' => ['user.view_sensitive', 'product.manage', 'fulfillment.manage', 'audit.view_own'],
            'finance' => ['user.view_masked', 'finance.manage', 'after_sale.review', 'push.view', 'push.settle', 'audit.view'],
            'auditor' => ['user.view_masked', 'task.view', 'audit.view'],
        ];

        $roleNames = [
            'super_admin' => '超级管理员',
            'platform_admin' => '平台管理员',
            'operations_admin' => '运营管理员',
            'campus_agent' => '学校代理',
            'task_reviewer' => '任务审核员',
            'community_reviewer' => '社区审核员',
            'customer_service' => '客服人员',
            'warehouse' => '仓储人员',
            'finance' => '财务人员',
            'auditor' => '审计人员',
        ];

        foreach ($rolePermissions as $code => $permissionCodes) {
            $role = Role::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $roleNames[$code], 'system_role' => true],
            );
            $role->permissions()->sync(
                Permission::query()->whereIn('code', $permissionCodes)->pluck('id'),
            );
        }
    }
}
