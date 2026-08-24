<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\UserIdentityVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class UserIdentityVerificationController extends Controller
{
    public function show(Request $request, UserIdentityVerificationService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($service->status($user));
    }

    public function store(Request $request, UserIdentityVerificationService $service): JsonResponse
    {
        $data = $request->validate([
            'real_name' => ['required', 'string', 'min:2', 'max:64', 'not_regex:/[<>{}\r\n]/'],
            'identity_number' => ['required', 'string', 'size:18'],
            'personal_information_consent' => ['required', 'accepted'],
        ]);
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->verify($user, $data['real_name'], $data['identity_number']);
        } catch (RuntimeException) {
            return ApiResponse::error('IDENTITY_PROVIDER_UNAVAILABLE', '实名认证服务暂时不可用，请稍后重试。', 503);
        }

        $message = match ($result['status']) {
            'verified' => '实名认证已通过。',
            'pending' => '实名认证资料已提交审核。',
            default => '姓名与身份证号未能通过核验。',
        };

        return ApiResponse::success($result, 200, $message);
    }
}
