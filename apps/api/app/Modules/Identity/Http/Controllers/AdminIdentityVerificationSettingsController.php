<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\IdentityVerificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminIdentityVerificationSettingsController extends Controller
{
    public function show(Request $request, IdentityVerificationSettingsService $settings): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }

        return ApiResponse::success($settings->snapshot());
    }

    public function update(Request $request, IdentityVerificationSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'driver' => ['required', 'in:disabled,manual,aliyun'],
            'collection_enabled' => ['required', 'boolean'],
            'manual_review_enabled' => ['required', 'boolean'],
            'aliyun' => ['sometimes', 'array'],
            'aliyun.region' => ['required_with:aliyun', 'string', 'max:64'],
            'aliyun.endpoint' => ['required_with:aliyun', 'string', 'max:255'],
            'aliyun.timeout_seconds' => ['required_with:aliyun', 'integer', 'min:1', 'max:30'],
            'access_key_id' => ['nullable', 'string', 'max:512'],
            'access_key_secret' => ['nullable', 'string', 'max:512'],
            'security_token' => ['nullable', 'string', 'max:4096'],
            'clear_access_key_id' => ['sometimes', 'boolean'],
            'clear_access_key_secret' => ['sometimes', 'boolean'],
            'clear_security_token' => ['sometimes', 'boolean'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $after = $settings->update([
            'driver' => $data['driver'],
            'collection_enabled' => $data['collection_enabled'],
            'manual_review_enabled' => $data['manual_review_enabled'],
            'aliyun' => $data['aliyun'] ?? $settings->effective()['aliyun'],
        ], collect($data)->only([
            'access_key_id', 'access_key_secret', 'security_token',
            'clear_access_key_id', 'clear_access_key_secret', 'clear_security_token',
        ])->all(), $data['reason'], $admin);
        $audit->record($admin, 'identity_verification.settings.update', 'identity_verification', 'default', $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, '实名认证配置已更新。');
    }

    private function isAuthorized(Request $request): bool
    {
        $admin = $request->user();

        return $admin instanceof AdminUser && $admin->hasPermission('settings.manage')
            && $admin->roles()->where('code', 'super_admin')->exists();
    }

    private function denied(): JsonResponse
    {
        return ApiResponse::error('ADMIN_PERMISSION_DENIED', '仅超级管理员可管理实名认证配置。', 403);
    }
}
