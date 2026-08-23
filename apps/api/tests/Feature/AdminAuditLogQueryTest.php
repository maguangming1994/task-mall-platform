<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Http\Controllers\AdminAuditLogController;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Audit\Services\AuditPayloadRedactor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminAuditLogQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth.admin', 'admin.permission:audit.view|audit.view_own'])
            ->get('/api/v1/testing/admin-audit-logs', [AdminAuditLogController::class, 'index']);
    }

    public function test_own_scope_only_returns_the_current_admin_logs_and_redacts_differences(): void
    {
        $this->seed(RbacSeeder::class);
        $owner = $this->admin('platform_admin', 'audit-owner');
        $other = $this->admin('platform_admin', 'audit-other');
        $this->log($owner, 'task.update', 'task', 'TASK-OWNER', [
            'title' => '旧标题',
            'password' => 'should-not-leak',
        ], [
            'title' => '新标题',
            'email' => 'owner@example.com',
        ], 'REQ-OWNER');
        $this->log($other, 'task.update', 'task', 'TASK-OTHER', null, ['title' => '其他人的任务'], 'REQ-OTHER');

        $response = $this->withToken($this->login($owner))
            ->getJson('/api/v1/testing/admin-audit-logs?action=task.update&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.scope', 'own')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.actor.id', $owner->public_id)
            ->assertJsonPath('data.items.0.before.password', AuditPayloadRedactor::REDACTED)
            ->assertJsonPath('data.items.0.after.email', AuditPayloadRedactor::REDACTED)
            ->assertJsonPath('data.items.0.after.title', '新标题');

        $this->assertSame('TASK-OWNER', $response->json('data.items.0.subject_id'));
    }

    public function test_global_scope_supports_actor_object_request_and_date_filters(): void
    {
        $this->seed(RbacSeeder::class);
        $auditor = $this->admin('auditor', 'audit-global');
        $operator = $this->admin('operations_admin', 'audit-operator');
        $this->log($operator, 'product.status_update', 'product', 'PRODUCT-1', ['status' => 'draft'], ['status' => 'published'], 'REQ-FILTER-1');
        $this->log($auditor, 'task.update', 'task', 'TASK-1', null, ['status' => 'published'], 'REQ-FILTER-2');

        $query = http_build_query([
            'action' => 'product.status_update',
            'subject_type' => 'product',
            'subject_id' => 'PRODUCT-1',
            'actor_id' => $operator->public_id,
            'request_id' => 'REQ-FILTER-1',
            'started_at' => now()->toDateString(),
            'ended_at' => now()->toDateString(),
            'per_page' => 5,
        ]);

        $this->withToken($this->login($auditor))
            ->getJson('/api/v1/testing/admin-audit-logs?'.$query)
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.page_size', 5)
            ->assertJsonPath('data.items.0.request_id', 'REQ-FILTER-1')
            ->assertJsonPath('data.items.0.actor.account', 'audit-operator');
    }

    public function test_admin_without_audit_permission_is_denied(): void
    {
        $this->seed(RbacSeeder::class);
        $role = Role::query()->create(['code' => 'without_audit', 'name' => '无审计权限', 'system_role' => false]);
        $admin = AdminUser::query()->create([
            'account' => 'audit-denied',
            'display_name' => '无审计权限管理员',
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);

        $this->withToken($this->login($admin))
            ->getJson('/api/v1/testing/admin-audit-logs')
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
    }

    private function admin(string $roleCode, string $account): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account.' 管理员',
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $admin;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(
        AdminUser $actor,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        string $requestId,
    ): OperationLog {
        return OperationLog::query()->create([
            'admin_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_value' => $before,
            'after_value' => $after,
            'reason' => '自动化测试审计原因',
            'request_id' => $requestId,
        ]);
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }
}
