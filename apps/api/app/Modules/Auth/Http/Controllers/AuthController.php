<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function loginWithPhone(Request $request, AuthService $auth): JsonResponse
    {
        $data = validator([
            ...$request->all(),
            'client_platform' => $request->header('X-Client-Platform'),
            'device_id' => $request->header('X-Device-Id'),
            'device_name' => $request->header('X-Device-Name'),
        ], [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'verification_code' => ['required', 'string', 'min:4', 'max:10'],
            'client_platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser'],
            'device_id' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ])->validate();

        try {
            $tokens = $auth->loginWithPhone(
                $this->normalizePhone($data['phone']),
                $data['verification_code'],
                $data['client_platform'],
                $data['device_id'],
                $data['device_name'],
            );
        } catch (AuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }

        return ApiResponse::success($tokens);
    }

    public function refresh(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string', 'min:32', 'max:200'],
        ]);

        try {
            return ApiResponse::success($auth->refresh($data['refresh_token']));
        } catch (AuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }
    }

    public function logout(Request $request, AuthService $auth): Response
    {
        $data = $request->validate([
            'all_devices' => ['sometimes', 'boolean'],
        ]);
        /** @var UserSession $session */
        $session = $request->attributes->get('auth_session');
        $auth->logout($session, (bool) ($data['all_devices'] ?? false));

        return response()->noContent();
    }

    private function normalizePhone(string $phone): string
    {
        return str_starts_with($phone, '+86') ? substr($phone, 3) : $phone;
    }
}
