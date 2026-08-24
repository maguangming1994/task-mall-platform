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

        return ApiResponse::success($settings->effective());
    }

    public function update(Request $request, IdentityVerificationSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return $this->denied();
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'driver' => ['required', 'in:disabled,manual'],
            'collection_enabled' => ['required', 'boolean'],
            'manual_review_enabled' => ['required', 'boolean'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->effective();
        $after = $settings->update([
            'driver' => $data['driver'],
            'collection_enabled' => $data['collection_enabled'],
            'manual_review_enabled' => $data['manual_review_enabled'],
        ], $data['reason'], $admin);
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
