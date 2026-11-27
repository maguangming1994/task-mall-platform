<?php

namespace App\Modules\Store\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Models\User;
use App\Modules\Store\Models\StoreManagerSession;
use App\Modules\Store\Models\StoreMember;
use App\Modules\Store\Services\StoreManagerAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateStoreManager
{
    public function __construct(private readonly StoreManagerAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token === null || $token === '' ? null : StoreManagerSession::query()
            ->with(['store'])
            ->where('token_hash', $this->auth->tokenHash($token))
            ->first();
        if ($session === null || $session->expires_at->isPast() || $session->revoked_at !== null) {
            return ApiResponse::error('STORE_AUTH_REQUIRED', '门店工作台登录已过期，请重新进入。', 401);
        }
        $store = $session->store;
        if ($store === null || ! $store->isActive()) {
            return ApiResponse::error('STORE_NOT_ACTIVE', '门店当前不可用，请联系平台运营。', 403);
        }
        $member = StoreMember::query()
            ->where('store_id', $store->id)
            ->where('user_id', $session->user_id)
            ->where('status', 'active')
            ->first();
        if ($member === null) {
            return ApiResponse::error('STORE_MEMBER_DISABLED', '当前账号的门店权限已被移除。', 403);
        }
        $user = User::query()->find($session->user_id);
        if ($user === null || $user->status !== 'active') {
            return ApiResponse::error('STORE_AUTH_REQUIRED', '账号状态异常，请重新登录。', 401);
        }

        $session->update(['last_active_at' => now()]);
        $request->setUserResolver(fn (): User => $user);
        $request->attributes->set('store_session', $session);
        $request->attributes->set('store', $store);
        $request->attributes->set('store_member', $member);

        return $next($request);
    }
}
