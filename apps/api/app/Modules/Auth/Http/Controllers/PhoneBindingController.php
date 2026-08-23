<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\WechatIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneBindingController extends Controller
{
    public function store(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'new_phone' => ['required', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'new_phone_verification_code' => ['required', 'string', 'min:4', 'max:10'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $phone = str_starts_with($data['new_phone'], '+86') ? substr($data['new_phone'], 3) : $data['new_phone'];

        try {
            return ApiResponse::success($auth->bindPhone($user, $phone, $data['new_phone_verification_code']));
        } catch (AuthException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function storeFromWechat(
        Request $request,
        AuthService $auth,
        WechatIdentityProvider $wechat,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:512'],
        ]);
        /** @var User $user */
        $user = $request->user();

        try {
            $phone = $wechat->exchangePhoneNumber($data['code']);

            return ApiResponse::success($auth->bindWechatPhone($user, $phone));
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
