<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WechatAuthController extends Controller
{
    public function mini(Request $request, AuthService $auth): JsonResponse
    {
        return $this->login($request, $auth, 'wechat_mini', 'wechat-mini');
    }

    public function officialAccount(Request $request, AuthService $auth): JsonResponse
    {
        return $this->login($request, $auth, 'wechat_oa', 'h5-wechat');
    }

    private function login(
        Request $request,
        AuthService $auth,
        string $provider,
        string $requiredPlatform,
    ): JsonResponse {
        $data = validator([
            ...$request->all(),
            'client_platform' => $request->header('X-Client-Platform'),
            'device_id' => $request->header('X-Device-Id'),
            'device_name' => $request->header('X-Device-Name'),
        ], [
            'code' => ['required', 'string', 'min:3', 'max:512'],
            'client_platform' => ['required', 'in:'.$requiredPlatform],
            'device_id' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ])->validate();

        try {
            return ApiResponse::success($auth->loginWithWechat(
                $provider,
                $data['code'],
                $data['client_platform'],
                $data['device_id'],
                $data['device_name'],
            ));
        } catch (AuthException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }
}
