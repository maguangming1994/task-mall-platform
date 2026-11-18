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

        // ResolveCampusScope 已在全局阶段校验并缓存会话，避免重复查询
        $session = $request->attributes->get('campus_scope_session');
        if (! $session instanceof UserSession) {
            $session = UserSession::query()
                ->with(['user', 'device', 'identity'])
                ->where('public_id', $payload['sid'])
                ->first();
        }

        if ($session === null
            || $session->user->public_id !== $payload['sub']
            || $session->revoked_at !== null
            || $session->expires_at->isPast()
            || $session->device->revoked_at !== null
            || $session->user->status !== 'active') {
            return ApiResponse::error('AUTH_SESSION_REVOKED', '登录状态已失效。', 401);
        }

        if ($session->user->phone_verified_at === null
            && str_starts_with($session->identity?->provider ?? '', 'wechat_')
            && ! $this->allowsPhoneBindingFlow($request)) {
            return ApiResponse::error('PHONE_BINDING_REQUIRED', '请先完成手机号绑定。', 403);
        }

        $request->setUserResolver(fn (): User => $session->user);
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }

    private function allowsPhoneBindingFlow(Request $request): bool
    {
        return $request->is(
            'api/v1/auth/logout',
            'api/v1/me',
            'api/v1/me/legal-consents',
            'api/v1/me/legal-consents/pending',
            'api/v1/me/phone',
            'api/v1/me/phone/wechat',
        );
    }
}
