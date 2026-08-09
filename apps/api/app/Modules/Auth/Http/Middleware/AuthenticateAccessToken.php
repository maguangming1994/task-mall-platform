<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Models\User;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateAccessToken
{
    public function __construct(private readonly AccessTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return ApiResponse::error('AUTH_REQUIRED', '请先登录。', 401);
        }

        try {
            $payload = $this->tokens->decode($token);
        } catch (Throwable) {
            return ApiResponse::error('AUTH_REQUIRED', '登录状态无效或已过期。', 401);
        }

        $session = UserSession::query()
            ->with(['user', 'device'])
            ->where('public_id', $payload['sid'])
            ->first();

        if ($session === null
            || $session->user->public_id !== $payload['sub']
            || $session->revoked_at !== null
            || $session->expires_at->isPast()
            || $session->device->revoked_at !== null
            || $session->user->status !== 'active') {
            return ApiResponse::error('AUTH_SESSION_REVOKED', '登录状态已失效。', 401);
        }

        $request->setUserResolver(fn (): User => $session->user);
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }
}
