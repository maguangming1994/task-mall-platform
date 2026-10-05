<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\AdminUser;

class AdminNavigationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(AdminUser $admin): array
    {
        $permissions = $admin->permissionCodes();
        $items = [
            ['code' => 'workbench', 'label' => '工作台', 'icon' => 'DashboardOutlined', 'path' => '/', 'permissions' => []],
            ['code' => 'users', 'label' => '用户中心', 'icon' => 'TeamOutlined', 'path' => '/users', 'permissions' => ['user.view_masked', 'user.view_sensitive']],
            ['code' => 'campuses', 'label' => '学校与代理', 'icon' => 'EnvironmentOutlined', 'path' => '/campuses', 'permissions' => ['campus.manage']],
            ['code' => 'privacy', 'label' => '协议与隐私', 'icon' => 'SafetyCertificateOutlined', 'path' => '/privacy', 'permissions' => ['legal.manage', 'privacy.manage']],
            ['code' => 'tasks', 'label' => '任务中心', 'icon' => 'ProfileOutlined', 'path' => '/tasks', 'permissions' => ['task.view', 'task.manage', 'task.review']],
            ['code' => 'task_templates', 'label' => '任务模板中心', 'icon' => 'ProfileOutlined', 'path' => '/task-templates', 'permissions' => ['task.view', 'task.manage']],
            ['code' => 'mall', 'label' => '商城中心', 'icon' => 'ShopOutlined', 'path' => '/mall', 'permissions' => ['product.manage']],
            ['code' => 'orders', 'label' => '订单与售后', 'icon' => 'FileDoneOutlined', 'path' => '/orders', 'permissions' => ['fulfillment.manage', 'after_sale.review']],
            ['code' => 'finance', 'label' => '资产与财务', 'icon' => 'WalletOutlined', 'path' => '/finance', 'permissions' => ['finance.manage']],
            ['code' => 'community', 'label' => '社区内容', 'icon' => 'MessageOutlined', 'path' => '/community', 'permissions' => ['community.review']],
            ['code' => 'second_hand', 'label' => '闲置审核', 'icon' => 'SwapOutlined', 'path' => '/second-hand', 'permissions' => ['community.review', 'after_sale.review']],
            ['code' => 'identity_review', 'label' => '实名审核', 'icon' => 'SafetyCertificateOutlined', 'path' => '/identity-review', 'permissions' => ['identity.review']],
            ['code' => 'support', 'label' => '客服工单', 'icon' => 'CustomerServiceOutlined', 'path' => '/support', 'permissions' => ['after_sale.review', 'privacy.manage']],
            ['code' => 'operations', 'label' => '运营与消息', 'icon' => 'NotificationOutlined', 'path' => '/operations', 'permissions' => ['operations.manage']],
            ['code' => 'data_board', 'label' => '数据看板', 'icon' => 'BarChartOutlined', 'path' => '/data-board', 'permissions' => ['operations.manage']],
            ['code' => 'settings', 'label' => '配置中心', 'icon' => 'SettingOutlined', 'path' => '/settings', 'permissions' => ['settings.manage']],
            ['code' => 'readiness', 'label' => '上线准备', 'icon' => 'SafetyCertificateOutlined', 'path' => '/readiness', 'permissions' => ['settings.manage']],
            ['code' => 'security', 'label' => '权限与审计', 'icon' => 'SafetyCertificateOutlined', 'path' => '/security', 'permissions' => ['rbac.manage', 'audit.view', 'audit.view_own']],
        ];

        return array_values(array_filter($items, function (array $item) use ($permissions): bool {
            return $item['permissions'] === []
                || array_intersect($item['permissions'], $permissions) !== [];
        }));
    }
}
