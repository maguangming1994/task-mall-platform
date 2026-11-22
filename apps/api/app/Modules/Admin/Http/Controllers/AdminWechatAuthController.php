<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Http\Responses\AdminAuthResponder;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Services\AdminAuthService;
use App\Modules\Admin\Services\AdminWechatBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 管理员微信绑定与免密登录。
 *
 * 绑定/解绑属于账号安全操作，要求近期完成密码（+动态码）验证。
 */
class AdminWechatAuthController extends Controller
{
    public function __construct(private readonly AdminAuthResponder $responder) {}

    /** 公众号 H5 免密登录：微信 code 换 openid，再匹配已绑定管理员 */
    public function login(
        Request $request,
        AdminWechatBindingService $bindings,
        AdminAuthService $auth,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        try {
            $admin = $bindings->resolve($data['code']);
            $result = $auth->loginWithTrustedIdentity($admin, $request->ip(), $request->userAgent());

            return $this->responder->make($request, $result, ($result['mfa_required'] ?? false) ? 202 : 200);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function show(Request $request, AdminWechatBindingService $bindings): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($bindings->present($admin));
    }

    public function bind(Request $request, AdminWechatBindingService $bindings): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        try {
            $bindings->bind($admin, $data['code']);

            return ApiResponse::success($bindings->present($admin));
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function unbind(Request $request, AdminWechatBindingService $bindings): Response
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $bindings->unbind($admin);

        return response()->noContent();
    }
}
