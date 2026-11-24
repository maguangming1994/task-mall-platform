<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Audit\Services\AuditLogger;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RbacAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_platform_admin_has_frontend_publish_permission(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'publisher-test',
            'display_name' => '平台发布测试员',
            'password' => 'local-password-only',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'platform_admin')->value('id'));

        $this->assertTrue($admin->hasPermission('task.frontend_publish'));
        $this->assertFalse($admin->hasPermission('finance.manage'));
        $this->assertDatabaseCount('roles', 10);
    }

    public function test_operations_admin_cannot_send_robot_messages(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'operations-robot-test',
            'display_name' => '运营机器人测试员',
            'password' => 'local-password-only',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'operations_admin')->value('id'));

        $this->assertTrue($admin->hasPermission('robot.view'));
        $this->assertTrue($admin->hasPermission('robot.settings_manage'));
        $this->assertFalse($admin->hasPermission('robot.message_send'));

        $superAdmin = AdminUser::query()->create([
            'account' => 'super-robot-test',
            'display_name' => '超级管理员机器人测试',
            'password' => 'local-password-only',
        ]);
        $superAdmin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $this->assertTrue($superAdmin->hasPermission('robot.message_send'));
    }

    public function test_operation_logs_are_append_only(): void
    {
        $log = app(AuditLogger::class)->record(
            null,
            'auth.test',
            'user',
            '01TESTSUBJECT0000000000000',
            null,
            ['status' => 'active'],
            'I1 audit test',
        );

        $this->assertDatabaseHas('operation_logs', ['action' => 'auth.test']);

        $this->expectException(LogicException::class);
        OperationLog::query()->findOrFail($log->id)->update(['reason' => 'changed']);
    }
}
