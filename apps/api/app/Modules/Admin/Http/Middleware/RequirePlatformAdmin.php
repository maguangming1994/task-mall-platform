<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅平台侧管理员可执行：校区代理对本模块只读，敏感操作（审核、停用、校区配置、结算）被拦截。
 */
class RequirePlatformAdmin
{
    public static function isCampusAgent(AdminUser $admin): bool
    {
        $roles = $admin->roles()->pluck('code');

        return ! $roles->contains('super_admin') && $roles->contains('campus_agent');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '请先登录管理后台。', 401);
        }
        if (self::isCampusAgent($admin)) {
            return ApiResponse::error('PLATFORM_ADMIN_REQUIRED', '该操作仅平台管理员可执行，校区代理仅可查看。', 403);
        }

        return $next($request);
    }
}
