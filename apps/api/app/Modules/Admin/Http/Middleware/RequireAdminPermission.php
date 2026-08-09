<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '请先登录管理后台。', 401);
        }

        foreach ($permissions as $permissionGroup) {
            $alternatives = array_filter(explode('|', $permissionGroup));
            $granted = collect($alternatives)->contains(
                fn (string $permission): bool => $admin->hasPermission($permission),
            );

            if (! $granted) {
                return ApiResponse::error('ADMIN_PERMISSION_DENIED', '当前账号无权执行此操作。', 403);
            }
        }

        return $next($request);
    }
}
