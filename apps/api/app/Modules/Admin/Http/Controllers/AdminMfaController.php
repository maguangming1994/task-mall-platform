<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Services\AdminMfaService;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMfaController extends Controller
{
    public function enroll(Request $request, AdminMfaService $mfa, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $mfa->enroll($admin);
        $audit->record($admin, 'admin.mfa.enroll_started', 'admin_user', $admin->public_id);

        return ApiResponse::success($result, 201, '请使用验证器扫描或输入密钥，并完成验证码确认。');
    }

    public function confirm(Request $request, AdminMfaService $mfa, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $codes = $mfa->confirm($admin, $data['code']);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
        $audit->record($admin, 'admin.mfa.enabled', 'admin_user', $admin->public_id);

        return ApiResponse::success(['recovery_codes' => $codes], 200, 'MFA 已启用；恢复码仅展示一次。');
    }
}
