<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

class RoleScreenshotAccountSeeder extends Seeder
{
    public const PASSWORD = 'RolePreview2026!';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Role screenshot accounts are restricted to local and testing environments.');
        }

        $this->call(RbacSeeder::class);

        $roles = [
            'super_admin' => '截图·超级管理员',
            'platform_admin' => '截图·平台管理员',
            'operations_admin' => '截图·运营管理员',
            'task_reviewer' => '截图·任务审核员',
            'community_reviewer' => '截图·社区审核员',
            'customer_service' => '截图·客服人员',
            'warehouse' => '截图·仓储人员',
            'finance' => '截图·财务人员',
            'auditor' => '截图·审计人员',
        ];

        foreach ($roles as $roleCode => $displayName) {
            $admin = AdminUser::query()->firstOrCreate(
                ['account' => 'role_'.$roleCode],
                [
                    'display_name' => $displayName,
                    'password' => self::PASSWORD,
                    'status' => 'active',
                ],
            );
            $admin->roles()->sync([Role::query()->where('code', $roleCode)->value('id')]);
        }

        $this->command?->info('Nine local-only role screenshot accounts are ready.');
    }
}
