<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Http\Responses\AdminAuthResponder;
use App\Modules\Admin\Models\AdminMfaChallenge;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Services\AdminAuthService;
use App\Modules\Admin\Services\AdminMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminAuthController extends Controller
{
    public function __construct(private readonly AdminAuthResponder $responder) {}

    public function login(Request $request, AdminAuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'min:3', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $auth->login(
                $data['account'],
                $data['password'],
                $request->ip(),
                $request->userAgent(),
                (bool) ($data['remember'] ?? false),
            );

            return $this->authResponse($request, $result, ($result['mfa_required'] ?? false) ? 202 : 200);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function verifyMfa(Request $request, AdminAuthService $auth, AdminMfaService $mfa): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:26'],
            'challenge_token' => ['required', 'string', 'min:32', 'max:100'],
            'code' => ['required', 'string', 'min:6', 'max:20'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        try {
            $challenge = AdminMfaChallenge::query()->where('public_id', $data['challenge_id'])->firstOrFail();
            $admin = $mfa->verifyChallenge($challenge, $data['challenge_token'], $data['code']);

            return $this->authResponse(
                $request,
                $auth->completeMfaLogin($admin, $request->ip(), $request->userAgent(), (bool) ($data['remember'] ?? false)),
            );
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function refresh(Request $request, AdminAuthService $auth): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['sometimes', 'string', 'min:32', 'max:200']]);
        $refreshToken = $data['refresh_token'] ?? $request->cookie($this->responder->cookieName());
        if (! is_string($refreshToken) || $refreshToken === '') {
            return ApiResponse::error('ADMIN_AUTH_SESSION_REVOKED', '后台登录状态无效或已过期。', 401);
        }

        try {
            return $this->authResponse($request, $auth->refresh(
                $refreshToken,
                $request->ip(),
                $request->userAgent(),
            ));
        } catch (AdminAuthException $exception) {
            $response = ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
            $this->responder->expireCookie($response, $request);

            return $response;
        }
    }

    public function logout(Request $request, AdminAuthService $auth): Response
    {
        $data = $request->validate(['all_sessions' => ['sometimes', 'boolean']]);
        /** @var AdminSession $session */
        $session = $request->attributes->get('admin_session');
        $auth->logout($session, (bool) ($data['all_sessions'] ?? false));

        $response = response()->noContent();
        $this->responder->expireCookie($response, $request);

        return $response;
    }

    public function verifyRecent(Request $request, AdminAuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'mfa_code' => ['sometimes', 'nullable', 'string', 'min:6', 'max:20'],
        ]);
        /** @var AdminSession $session */
        $session = $request->attributes->get('admin_session');
        try {
            $auth->verifyRecent($session, $data['password'], $data['mfa_code'] ?? null);

            return ApiResponse::success(['verified_at' => now()->toIso8601String()]);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function changePassword(Request $request, AdminAuthService $auth): Response|JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'min:8', 'max:200'],
            'new_password' => ['required', 'string', 'min:12', 'max:200', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'mfa_code' => ['sometimes', 'nullable', 'string', 'min:6', 'max:20'],
        ]);
        /** @var AdminSession $session */
        $session = $request->attributes->get('admin_session');

        try {
            $auth->changePassword($session, $data['current_password'], $data['new_password'], $data['mfa_code'] ?? null);

            return response()->noContent();
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    /** @param array<string, mixed> $result */
    private function authResponse(Request $request, array $result, int $status = 200): JsonResponse
    {
        return $this->responder->make($request, $result, $status);
    }
}
