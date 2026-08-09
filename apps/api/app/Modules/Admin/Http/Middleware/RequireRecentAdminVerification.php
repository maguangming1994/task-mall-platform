<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAdminVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('admin_session');
        $ttl = max(60, (int) config('admin_auth.recent_verification_seconds'));
        if (! $session instanceof AdminSession || $session->verified_at === null || $session->verified_at->lt(now()->subSeconds($ttl))) {
            return ApiResponse::error('ADMIN_RECENT_VERIFICATION_REQUIRED', '该高风险操作需要近期完成密码或 MFA 验证。', 403);
        }

        return $next($request);
    }
}
