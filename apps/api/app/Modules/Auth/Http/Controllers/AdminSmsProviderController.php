<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Exceptions\PhoneVerificationRateLimitedException;
use App\Modules\Auth\Services\AliyunSmsSender;
use App\Modules\Auth\Services\SmsProviderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSmsProviderController extends Controller
{
    public function show(Request $request, SmsProviderSettingsService $settings): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理短信网关配置。', 403);
        }

        return ApiResponse::success($settings->snapshot());
    }

    public function update(
        Request $request,
        SmsProviderSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理短信网关配置。', 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'settings' => ['sometimes', 'array'],
            'driver' => ['sometimes', 'string', 'max:24'],
            'ttl_seconds' => ['sometimes', 'integer'],
            'max_attempts' => ['sometimes', 'integer'],
            'resend_interval_seconds' => ['sometimes', 'integer'],
            'hourly_limit' => ['sometimes', 'integer'],
            'daily_limit' => ['sometimes', 'integer'],
            'aliyun' => ['sometimes', 'array'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sign_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'template_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'verification_variables' => ['sometimes', 'array', 'min:1'],
            'verification_variables.*' => ['string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,31}$/'],
            'timeout_seconds' => ['sometimes', 'integer'],
            'credentials' => ['sometimes', 'array'],
            'credentials.access_key_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'credentials.access_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'credentials.security_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'access_key_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'access_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'security_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'clear_access_key_id' => ['sometimes', 'boolean'],
            'clear_access_key_secret' => ['sometimes', 'boolean'],
            'clear_security_token' => ['sometimes', 'boolean'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $settingsInput = $data['settings'] ?? Arr::except($data, [
            'reason', 'credentials', 'access_key_id', 'access_key_secret', 'security_token',
            'clear_access_key_id', 'clear_access_key_secret', 'clear_security_token',
        ]);
        $nextSettings = $settings->buildSettings($settingsInput);
        $credentials = array_merge(
            Arr::only($data, ['access_key_id', 'access_key_secret', 'security_token']),
            Arr::only($data['credentials'] ?? [], ['access_key_id', 'access_key_secret', 'security_token']),
        );
        foreach (['access_key_id', 'access_key_secret', 'security_token'] as $field) {
            if (($data['clear_'.$field] ?? false) === true && filled($credentials[$field] ?? null)) {
                throw ValidationException::withMessages([
                    $field => '不能同时提交新的密钥值并勾选清除。',
                ]);
            }
            if (array_key_exists($field, $credentials) && blank($credentials[$field])) {
                unset($credentials[$field]);
            }
            if (($data['clear_'.$field] ?? false) === true) {
                $credentials[$field] = null;
            }
        }
        $after = $settings->update($nextSettings, $credentials, $data['reason'], $admin);
        $audit->record(
            $admin,
            'sms.aliyun.update',
            'sms_provider',
            'aliyun',
            $before,
            $after,
            $data['reason'],
        );

        return ApiResponse::success($after, 200, '阿里云短信配置已更新。');
    }

    public function test(
        Request $request,
        SmsProviderSettingsService $settings,
        AliyunSmsSender $sender,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理短信网关配置。', 403);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $result = $settings->test($data['phone'], $admin, $sender);
            $audit->record(
                $admin,
                'sms.aliyun.test',
                'sms_provider',
                'aliyun',
                null,
                ['accepted' => (bool) ($result['accepted'] ?? false)],
                '管理员发起短信连通性测试。',
            );
        } catch (PhoneVerificationRateLimitedException $exception) {
            $audit->record(
                $admin,
                'sms.aliyun.test_rate_limited',
                'sms_provider',
                'aliyun',
                null,
                ['accepted' => false, 'reason' => 'rate_limited'],
                '管理员短信测试触发手机号频控。',
            );

            return ApiResponse::error(
                'PHONE_VERIFICATION_RATE_LIMITED',
                '短信测试发送过于频繁，请稍后再试。',
                429,
                ['retry_after_seconds' => $exception->retryAfterSeconds],
            );
        } catch (Throwable $exception) {
            $audit->record(
                $admin,
                'sms.aliyun.test_failed',
                'sms_provider',
                'aliyun',
                null,
                ['accepted' => false, 'reason' => 'provider_error'],
                '管理员短信测试失败。',
            );
            throw $exception;
        }

        return ApiResponse::success($result, 200, '短信测试请求已提交。');
    }

    private function isAuthorized(Request $request): bool
    {
        $admin = $request->user();

        return $admin instanceof AdminUser
            && $admin->hasPermission('settings.manage')
            && $admin->roles()->where('code', 'super_admin')->exists();
    }
}
