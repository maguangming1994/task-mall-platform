<?php

namespace App\Modules\Task\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use App\Modules\Task\Services\TaskPublisherAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTaskPublisher
{
    public function __construct(private readonly TaskPublisherAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token === null ? null : TaskPublisherSession::query()->with('publisher.adminUser')->where('token_hash', $this->auth->tokenHash($token))->first();
        if ($session === null || $session->revoked_at !== null || $session->expires_at->isPast()) {
            return ApiResponse::error('PUBLISHER_AUTH_REQUIRED', '发布者会话无效或已过期。', 401);
        }
        $publisher = $session->publisher;
        if ($publisher->status !== 'active' || $publisher->adminUser->status !== 'active'
            || ! $publisher->adminUser->roles()->where('code', 'platform_admin')->exists()
            || ! $publisher->adminUser->hasPermission('task.frontend_publish')) {
            return ApiResponse::error('PUBLISHER_PERMISSION_REVOKED', '发布权限已撤销。', 403);
        }
        $session->update(['last_active_at' => now()]);
        $request->setUserResolver(fn (): TaskPublisher => $publisher);
        $request->attributes->set('publisher_session', $session);

        return $next($request);
    }
}
