<?php

namespace App\Modules\Task\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use App\Modules\Task\Services\TaskPublisherAuthService;
use App\Modules\Task\Services\TaskPublishingPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTaskPublisher
{
    public function __construct(
        private readonly TaskPublisherAuthService $auth,
        private readonly TaskPublishingPermissionService $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token === null ? null : TaskPublisherSession::query()
            ->with(['publisher.adminUser', 'publisher.linkedUser'])
            ->where('token_hash', $this->auth->tokenHash($token))
            ->first();
        if ($session === null || $session->expires_at->isPast()) {
            return ApiResponse::error('PUBLISHER_AUTH_REQUIRED', '发布者会话无效或已过期。', 401);
        }
        $publisher = $session->publisher;
        if (! $this->permissions->publisherCanPublish($publisher)) {
            return ApiResponse::error('PUBLISHER_PERMISSION_REVOKED', '发布权限已撤销。', 403);
        }
        if ($session->revoked_at !== null) {
            return ApiResponse::error('PUBLISHER_AUTH_REQUIRED', '发布者会话无效或已过期。', 401);
        }
        $session->update(['last_active_at' => now()]);
        $request->setUserResolver(fn (): TaskPublisher => $publisher);
        $request->attributes->set('publisher_session', $session);

        return $next($request);
    }
}
