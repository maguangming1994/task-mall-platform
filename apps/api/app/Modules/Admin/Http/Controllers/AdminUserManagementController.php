<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Admin\Services\AdminAuthService;
use App\Modules\Admin\Services\AdminMfaService;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserManagementController extends Controller
{
    public function index(Request $request, AdminAuthService $auth): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:active,disabled'], 'keyword' => ['sometimes', 'string', 'max:100']]);
        $items = AdminUser::query()->with('roles')->withCount(['sessions as active_sessions_count' => fn ($query) => $query->whereNull('revoked_at')->where('expires_at', '>', now())])
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['keyword']), fn ($query) => $query->where(fn ($nested) => $nested->where('account', 'like', '%'.$data['keyword'].'%')->orWhere('display_name', 'like', '%'.$data['keyword'].'%')))
            ->orderBy('account')->get()->map(fn (AdminUser $admin) => [...$auth->present($admin), 'active_sessions_count' => $admin->active_sessions_count, 'last_login_at' => $admin->last_login_at?->toIso8601String()]);

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request, AdminAuthService $auth, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:admin_users,account'],
            'display_name' => ['required', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'role_codes' => ['required', 'array', 'min:1'],
            'role_codes.*' => ['string', Rule::exists('roles', 'code')],
        ]);
        /** @var AdminUser $actor */
        $actor = $request->user();
        $admin = DB::transaction(function () use ($data): AdminUser {
            $admin = AdminUser::query()->create([
                'account' => mb_strtolower($data['account']), 'display_name' => $data['display_name'], 'email' => $data['email'] ?? null,
                'password' => $data['password'], 'status' => 'active', 'requires_mfa' => false,
            ]);
            $admin->roles()->sync(Role::query()->whereIn('code', $data['role_codes'])->pluck('id'));

            return $admin;
        });
        $presented = $auth->present($admin);
        $audit->record($actor, 'admin_user.create', 'admin_user', $admin->public_id, null, $presented);

        return ApiResponse::success($presented, 201, '管理员账号已创建。');
    }

    public function roles(Request $request, AdminUser $adminUser, AdminAuthService $auth, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['role_codes' => ['required', 'array', 'min:1'], 'role_codes.*' => ['string', Rule::exists('roles', 'code')], 'reason' => ['required', 'string', 'min:4', 'max:500']]);
        /** @var AdminUser $actor */
        $actor = $request->user();
        if ($actor->is($adminUser) && ! in_array('super_admin', $data['role_codes'], true) && $adminUser->roles()->where('code', 'super_admin')->exists()) {
            throw ValidationException::withMessages(['role_codes' => ['不能移除自己当前的超级管理员角色。']]);
        }
        $before = $auth->present($adminUser);
        $adminUser->roles()->sync(Role::query()->whereIn('code', $data['role_codes'])->pluck('id'));
        $after = $auth->present($adminUser->fresh());
        $audit->record($actor, 'admin_user.roles_update', 'admin_user', $adminUser->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function status(Request $request, AdminUser $adminUser, AdminAuthService $auth, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,disabled'], 'reason' => ['required', 'string', 'min:4', 'max:500']]);
        /** @var AdminUser $actor */
        $actor = $request->user();
        if ($actor->is($adminUser) && $data['status'] === 'disabled') {
            throw ValidationException::withMessages(['status' => ['不能停用当前登录账号。']]);
        }
        $before = $auth->present($adminUser);
        DB::transaction(function () use ($adminUser, $data): void {
            $adminUser->update(['status' => $data['status']]);
            if ($data['status'] === 'disabled') {
                AdminSession::query()->where('admin_user_id', $adminUser->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'account_disabled', 'updated_at' => now()]);
            }
        });
        $after = $auth->present($adminUser->fresh());
        $audit->record($actor, 'admin_user.status_update', 'admin_user', $adminUser->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function revokeSessions(Request $request, AdminUser $adminUser, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        /** @var AdminUser $actor */
        $actor = $request->user();
        $count = AdminSession::query()->where('admin_user_id', $adminUser->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'admin_revoked', 'updated_at' => now()]);
        $audit->record($actor, 'admin_user.sessions_revoke', 'admin_user', $adminUser->public_id, null, ['revoked_count' => $count], $data['reason']);

        return ApiResponse::success(['revoked_count' => $count]);
    }

    public function resetMfa(Request $request, AdminUser $adminUser, AdminMfaService $mfa, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        /** @var AdminUser $actor */
        $actor = $request->user();
        $mfa->reset($adminUser);
        AdminSession::query()->where('admin_user_id', $adminUser->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'mfa_reset', 'updated_at' => now()]);
        $audit->record($actor, 'admin_user.mfa_reset', 'admin_user', $adminUser->public_id, null, ['requires_mfa' => false], $data['reason']);

        return ApiResponse::success(['requires_mfa' => false]);
    }
}
