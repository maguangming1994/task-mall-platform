<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payment\Services\WechatPaymentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminWechatPaymentSettingsController extends Controller
{
    public function show(Request $request, WechatPaymentSettingsService $settings): JsonResponse
    {
        if (! $this->isAuthorized($request)) return $this->denied();

        return ApiResponse::success($settings->snapshot());
    }

    public function update(Request $request, WechatPaymentSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        if (! $this->isAuthorized($request)) return $this->denied();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'driver' => ['required', 'in:local,wechat'],
            'merchant_id' => ['present', 'nullable', 'string', 'max:64'],
            'merchant_certificate_serial' => ['present', 'nullable', 'string', 'max:128'],
            'notify_url' => ['present', 'nullable', 'url:https', 'max:1000'],
            'refund_notify_url' => ['present', 'nullable', 'url:https', 'max:1000'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $after = $settings->update($settings->buildSettings(Arr::except($data, ['reason'])), [], $data['reason'], $admin);
        $audit->record($admin, 'wechat_payment.settings.update', 'wechat_payment', 'wechat_pay_v3', $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, '微信支付配置已更新。');
    }

    public function credentials(Request $request, WechatPaymentSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        if (! $this->isAuthorized($request)) return $this->denied();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'api_v3_key' => ['sometimes', 'nullable', 'string', 'size:32'],
            'merchant_private_key' => ['sometimes', 'file', 'max:16', 'extensions:pem,key'],
            'platform_certificate' => ['sometimes', 'file', 'max:32', 'extensions:pem,cer,crt'],
            'clear_api_v3_key' => ['sometimes', 'boolean'],
            'clear_merchant_private_key' => ['sometimes', 'boolean'],
            'clear_platform_certificate' => ['sometimes', 'boolean'],
        ]);
        $credentials = [];
        if (filled($data['api_v3_key'] ?? null)) $credentials['api_v3_key'] = $data['api_v3_key'];
        foreach (['merchant_private_key', 'platform_certificate'] as $field) {
            if (($data[$field] ?? null) instanceof UploadedFile) {
                $credentials[$field] = file_get_contents($data[$field]->getRealPath());
            }
        }
        foreach (['api_v3_key', 'merchant_private_key', 'platform_certificate'] as $field) {
            if (($data['clear_'.$field] ?? false) === true) {
                if (array_key_exists($field, $credentials)) {
                    throw ValidationException::withMessages([$field => '不能同时上传新值并勾选清除。']);
                }
                $credentials[$field] = null;
            }
        }
        if ($credentials === []) {
            throw ValidationException::withMessages(['credentials' => '请提供至少一个密钥或证书变更。']);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $current = $settings->buildSettings(Arr::only($settings->effective(), [
            'driver', 'merchant_id', 'merchant_certificate_serial', 'notify_url', 'refund_notify_url', 'timeout_seconds',
        ]));
        $after = $settings->update($current, $credentials, $data['reason'], $admin);
        $audit->record($admin, 'wechat_payment.credentials.update', 'wechat_payment', 'wechat_pay_v3', $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, '微信支付密钥和证书已更新。');
    }

    public function test(Request $request, WechatPaymentSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        if (! $this->isAuthorized($request)) return $this->denied();
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $result = $settings->test();
            $audit->record($admin, 'wechat_payment.test', 'wechat_payment', 'wechat_pay_v3', null, ['accepted' => true], '管理员发起微信支付连接测试。');
        } catch (Throwable $exception) {
            $audit->record($admin, 'wechat_payment.test_failed', 'wechat_payment', 'wechat_pay_v3', null, ['accepted' => false], '微信支付连接测试失败。');
            throw $exception;
        }

        return ApiResponse::success($result);
    }

    private function isAuthorized(Request $request): bool
    {
        $admin = $request->user();
        return $admin instanceof AdminUser && $admin->hasPermission('settings.manage')
            && $admin->roles()->where('code', 'super_admin')->exists();
    }

    private function denied(): JsonResponse
    {
        return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理微信支付配置。', 403);
    }
}
