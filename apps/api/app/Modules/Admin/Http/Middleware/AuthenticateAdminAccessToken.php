<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Services\AdminAccessTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateAdminAccessToken
{
    public function __construct(private readonly AdminAccessTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '请先登录管理后台。', 401);
        }

        try {
            $payload = $this->tokens->decode($token);
        } catch (Throwable) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '后台登录状态无效或已过期。', 401);
        }

        $session = AdminSession::query()
            ->with('adminUser')
            ->where('public_id', $payload['sid'])
            ->first();

        if ($session === null
            || $session->adminUser->public_id !== $payload['sub']
            || $session->revoked_at !== null
            || $session->expires_at->isPast()
            || $session->idle_expires_at->isPast()
            || $session->adminUser->status !== 'active') {
            return ApiResponse::error('ADMIN_AUTH_SESSION_REVOKED', '后台登录状态已失效。', 401);
        }

        $idleExpiry = now()->addSeconds(max(60, (int) config('admin_auth.idle_ttl_seconds')));
        if ($idleExpiry->greaterThan($session->expires_at)) {
            $idleExpiry = $session->expires_at;
        }
        $session->update(['last_active_at' => now(), 'idle_expires_at' => $idleExpiry]);

        $request->setUserResolver(fn (): AdminUser => $session->adminUser);
        $request->attributes->set('admin_session', $session);

        return $next($request);
    }
}
