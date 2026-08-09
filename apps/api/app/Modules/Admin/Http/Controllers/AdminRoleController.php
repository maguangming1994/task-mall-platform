<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $items = Role::query()->with('permissions')->orderBy('code')->get()->map(fn (Role $role) => [
            'code' => $role->code, 'name' => $role->name, 'description' => $role->description,
            'system_role' => $role->system_role, 'permissions' => $role->permissions->pluck('code')->sort()->values(),
            'permission_items' => $role->permissions->sortBy('code')->values()->map(fn ($permission) => [
                'code' => $permission->code,
                'name' => $permission->name,
                'group' => $permission->group,
            ]),
        ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(['items' => Permission::query()->orderBy('group')->orderBy('code')->get()->map(fn (Permission $permission) => [
            'code' => $permission->code,
            'name' => $permission->name,
            'group' => $permission->group,
            'description' => $permission->description,
        ])]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateRole($request, true);
        /** @var AdminUser $actor */ $actor = $request->user();
        $role = DB::transaction(function () use ($data): Role {
            $role = Role::query()->create([
                'code' => mb_strtolower($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'system_role' => false,
            ]);
            $role->permissions()->sync(Permission::query()->whereIn('code', $data['permission_codes'])->pluck('id'));

            return $role;
        });
        $after = $this->present($role->fresh('permissions'));
        $audit->record($actor, 'admin_role.create', 'role', $role->code, null, $after, $data['reason']);

        return ApiResponse::success($after, 201, '角色已创建。');
    }

    public function update(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateRole($request, false);
        /** @var AdminUser $actor */ $actor = $request->user();
        $permissionCodes = collect($data['permission_codes'])->sort()->values();
        if ($role->code === 'super_admin') {
            $allPermissionCodes = Permission::query()->pluck('code')->sort()->values();
            if ($permissionCodes->all() !== $allPermissionCodes->all()) {
                throw ValidationException::withMessages(['permission_codes' => ['超级管理员必须保留全部系统权限。']]);
            }
        }
        if ($role->adminUsers()->whereKey($actor->id)->exists() && ! $permissionCodes->contains('rbac.manage')) {
            $hasOtherRbacRole = $actor->roles()->where('roles.id', '<>', $role->id)->whereHas('permissions', fn ($query) => $query->where('code', 'rbac.manage'))->exists();
            if (! $hasOtherRbacRole) {
                throw ValidationException::withMessages(['permission_codes' => ['不能通过修改当前角色移除自己的角色管理权限。']]);
            }
        }

        $before = $this->present($role->load('permissions'));
        DB::transaction(function () use ($role, $data): void {
            $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->permissions()->sync(Permission::query()->whereIn('code', $data['permission_codes'])->pluck('id'));
        });
        $after = $this->present($role->fresh('permissions'));
        $audit->record($actor, 'admin_role.update', 'role', $role->code, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    /** @return array<string,mixed> */
    private function validateRole(Request $request, bool $creating): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:50', 'regex:/^[a-z][a-z0-9_]+$/', $creating ? Rule::unique('roles', 'code') : Rule::unique('roles', 'code')->ignore($request->route('role'))],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_codes' => ['required', 'array', 'min:1'],
            'permission_codes.*' => ['string', 'distinct', Rule::exists('permissions', 'code')],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);
    }

    /** @return array<string,mixed> */
    private function present(Role $role): array
    {
        return [
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'system_role' => $role->system_role,
            'permissions' => $role->permissions->pluck('code')->sort()->values(),
            'permission_items' => $role->permissions->sortBy('code')->values()->map(fn ($permission) => ['code' => $permission->code, 'name' => $permission->name, 'group' => $permission->group]),
        ];
    }
}
