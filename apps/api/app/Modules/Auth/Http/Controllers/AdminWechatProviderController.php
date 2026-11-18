<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminWechatProviderController extends Controller
{
    public function show(Request $request, WechatProviderSettingsService $settings): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }

        return ApiResponse::success($settings->snapshot());
    }

    public function update(
        Request $request,
        string $provider,
        WechatProviderSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'driver' => ['required', 'in:local,wechat'],
            'app_id' => ['present', 'nullable', 'string', 'max:64'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:30'],
            'login_enabled' => ['sometimes', 'boolean'],
            'app_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'encoding_aes_key' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'clear_app_secret' => ['sometimes', 'boolean'],
            'clear_token' => ['sometimes', 'boolean'],
            'clear_encoding_aes_key' => ['sometimes', 'boolean'],
        ]);
        if ($provider === 'mini' && Arr::hasAny($data, ['token', 'encoding_aes_key', 'clear_token', 'clear_encoding_aes_key'])) {
            throw ValidationException::withMessages(['provider' => '小程序不支持公众号消息加密字段。']);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot()[$provider] ?? null;
        $nextSettings = $settings->buildSettings($provider, Arr::only($data, ['driver', 'app_id', 'timeout_seconds', 'login_enabled']));
        $credentials = [];
        foreach (['app_secret', 'token', 'encoding_aes_key'] as $field) {
            if (($data['clear_'.$field] ?? false) && filled($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => '不能同时输入新值并勾选清除。']);
            }
            if (filled($data[$field] ?? null)) {
                $credentials[$field] = trim((string) $data[$field]);
            } elseif (($data['clear_'.$field] ?? false) === true) {
                $credentials[$field] = null;
            }
        }
        $after = $settings->update($provider, $nextSettings, $credentials, $data['reason'], $admin);
        $audit->record(
            $admin,
            'wechat.'.$provider.'.update',
            'wechat_provider',
            $provider,
            $before,
            $after,
            $data['reason'],
        );

        return ApiResponse::success($after, 200, '微信应用配置已更新。');
    }

    public function test(
        Request $request,
        string $provider,
        WechatProviderSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $result = $settings->test($provider);
            $audit->record($admin, 'wechat.'.$provider.'.test', 'wechat_provider', $provider, null, ['accepted' => true], '管理员发起微信接口连接测试。');
        } catch (Throwable $exception) {
            $audit->record($admin, 'wechat.'.$provider.'.test_failed', 'wechat_provider', $provider, null, ['accepted' => false], '微信接口连接测试失败。');
            throw $exception;
        }

        return ApiResponse::success($result, 200, '微信接口连接测试完成。');
    }

    public function uploadVerificationFile(
        Request $request,
        WechatProviderSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }
        $request->validate(['file' => ['required', 'file', 'max:4', 'extensions:txt']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $settings->storeVerificationFile($request->file('file'), $admin);
        $audit->record($admin, 'wechat.verification_file.upload', 'wechat_verification_file', $result['filename'], null, $result, '上传微信公众平台验证文件。');

        return ApiResponse::success($result, 201, '微信验证文件已发布。');
    }

    public function deleteVerificationFile(
        Request $request,
        string $filename,
        WechatProviderSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }
        if (! $settings->deleteVerificationFile($filename)) {
            return ApiResponse::error('WECHAT_VERIFICATION_FILE_NOT_FOUND', '微信验证文件不存在。', 404);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $audit->record($admin, 'wechat.verification_file.delete', 'wechat_verification_file', $filename, ['filename' => $filename], null, '删除微信公众平台验证文件。');

        return ApiResponse::success(null, 200, '微信验证文件已删除。');
    }

    private function isAuthorized(Request $request): bool
    {
        $admin = $request->user();

        return $admin instanceof AdminUser
            && $admin->hasPermission('settings.manage')
            && $admin->roles()->where('code', 'super_admin')->exists();
    }

    private function denied(): JsonResponse
    {
        return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理微信应用配置。', 403);
    }
}
