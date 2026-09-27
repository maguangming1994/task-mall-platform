<?php

namespace App\Modules\Campus\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminCampusScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '请先登录管理后台。', 401);
        }

        $isSuperAdmin = $admin->roles()->where('code', 'super_admin')->exists();
        $isCampusAgent = ! $isSuperAdmin && $admin->roles()->where('code', 'campus_agent')->exists();
        $requestedId = $request->attributes->get('campus_database_id');
        if ($isCampusAgent) {
            $assigned = $admin->campuses()->pluck('campuses.id');
            if ($assigned->isEmpty()) {
                if ($request->is('api/v1/admin/me', 'api/v1/admin/navigation', 'api/v1/admin/auth/*')) {
                    return $next($request);
                }

                return ApiResponse::error('CAMPUS_NOT_ASSIGNED', '当前代理账号尚未分配学校。', 403);
            }
            if (! is_int($requestedId)) {
                $requestedId = (int) ($admin->campuses()->wherePivot('is_primary', true)->value('campuses.id') ?? $assigned->first());
            }
            if (! $assigned->contains($requestedId)) {
                return ApiResponse::error('CAMPUS_SCOPE_DENIED', '当前代理账号无权管理该学校。', 403);
            }
        }

        if (is_int($requestedId)) {
            $request->attributes->set('campus_database_id', $requestedId);
            $request->attributes->set('campus_context_strict', true);
        }

        $routeCampus = $request->route('campus');
        if ($isCampusAgent && $routeCampus instanceof Campus && $routeCampus->id !== $requestedId) {
            return ApiResponse::error('CAMPUS_SCOPE_DENIED', '当前代理账号无权管理该学校。', 403);
        }

        return $next($request);
    }
}
