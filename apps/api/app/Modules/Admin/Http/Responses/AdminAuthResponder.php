<?php

namespace App\Modules\Admin\Http\Responses;

use App\Common\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 管理端登录响应：remembered 会话只通过 HttpOnly Cookie 下发刷新令牌，
 * 避免前端脚本读取长期凭据。登录与微信免密登录共用同一规则。
 */
final class AdminAuthResponder
{
    private const REMEMBER_COOKIE_PATH = '/api/v1/admin/auth';

    /** @param array<string, mixed> $result */
    public function make(Request $request, array $result, int $status = 200): JsonResponse
    {
        if (($result['mfa_required'] ?? false) === true) {
            return ApiResponse::success($result, $status);
        }

        $remembered = (bool) ($result['remembered'] ?? false);
        $refreshToken = $result['refresh_token'] ?? null;
        if ($remembered) {
            unset($result['refresh_token']);
        }

        $response = ApiResponse::success($result, $status);
        if ($remembered && is_string($refreshToken) && $refreshToken !== '') {
            $expiresAt = new \DateTimeImmutable((string) $result['refresh_expires_at']);
            $response->headers->setCookie(Cookie::create(
                $this->cookieName(),
                $refreshToken,
                $expiresAt,
                self::REMEMBER_COOKIE_PATH,
                null,
                $this->secureCookie($request),
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
        } else {
            $this->expireCookie($response, $request);
        }

        return $response;
    }

    public function cookieName(): string
    {
        return (string) config('admin_auth.remember_cookie_name');
    }

    /** 失效记住登录 Cookie（刷新失败或退出登录时调用） */
    public function expireCookie(JsonResponse|Response $response, Request $request): void
    {
        $response->headers->setCookie(Cookie::create(
            $this->cookieName(),
            '',
            1,
            self::REMEMBER_COOKIE_PATH,
            null,
            $this->secureCookie($request),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    private function secureCookie(Request $request): bool
    {
        return $request->isSecure() || app()->environment('production');
    }
}
