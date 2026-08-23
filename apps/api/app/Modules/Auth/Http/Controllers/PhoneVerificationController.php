<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Exceptions\PhoneVerificationRateLimitedException;
use App\Modules\Auth\Services\PhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PhoneVerificationController extends Controller
{
    public function store(Request $request, PhoneVerificationService $verification): JsonResponse
    {
        $data = validator([
            ...$request->all(),
            'client_platform' => $request->header('X-Client-Platform'),
            'device_id' => $request->header('X-Device-Id'),
        ], [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'purpose' => ['required', 'in:login,bind_phone,change_phone_old,change_phone_new'],
            'client_platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser'],
            'device_id' => ['required', 'string', 'max:100'],
        ])->validate();

        try {
            $verification->issue($this->normalizePhone($data['phone']), $data['purpose']);
        } catch (PhoneVerificationRateLimitedException $exception) {
            return ApiResponse::error(
                'PHONE_VERIFICATION_RATE_LIMITED',
                '验证码发送过于频繁，请稍后再试。',
                429,
                ['retry_after_seconds' => $exception->retryAfterSeconds],
            );
        } catch (RuntimeException) {
            return ApiResponse::error(
                'PHONE_VERIFICATION_PROVIDER_UNAVAILABLE',
                '验证码服务暂不可用。',
                503,
            );
        }

        return ApiResponse::success(null, 202, '验证码已发送，请注意查收。');
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/[^0-9+]/', '', $phone) ?? $phone;

        return str_starts_with($normalized, '+86') ? substr($normalized, 3) : $normalized;
    }
}
