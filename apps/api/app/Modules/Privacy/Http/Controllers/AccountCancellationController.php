<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Privacy\Models\AccountCancellationRequest;
use App\Modules\Privacy\Services\AccountCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccountCancellationController extends Controller
{
    public function eligibility(Request $request, AccountCancellationService $service): JsonResponse
    {
        return ApiResponse::success($service->eligibility($request->user()));
    }

    public function show(Request $request): JsonResponse
    {
        $item = AccountCancellationRequest::query()->where('user_id', $request->user()->id)->latest('id')->first();
        if ($item === null) {
            return ApiResponse::error('ACCOUNT_CANCELLATION_NOT_FOUND', '尚未提交注销申请。', 404);
        }

        return ApiResponse::success($this->present($item));
    }

    public function store(Request $request, AccountCancellationService $service): JsonResponse
    {
        $request->validate(['forfeit_points_and_coupons' => ['required', 'accepted'], 'confirmation' => ['required', 'in:CONFIRM_ACCOUNT_CANCELLATION']]);
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 12 || strlen($key) > 128) {
            return ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', '请提供 12 至 128 字符的幂等键。', 422);
        }
        /** @var User $user */ $user = $request->user();

        return ApiResponse::success($this->present($service->request($user, $key)), 201, '注销申请已进入冷静期。');
    }

    public function destroy(Request $request, AccountCancellationService $service): Response
    {
        $service->withdraw($request->user());

        return response()->noContent();
    }

    /** @return array<string,mixed> */
    private function present(AccountCancellationRequest $item): array
    {
        return ['id' => $item->public_id, 'status' => $item->status, 'requested_at' => $item->created_at?->toIso8601String(), 'cooling_off_ends_at' => $item->cooling_off_ends_at?->toIso8601String(), 'cancelled_at' => $item->cancelled_at?->toIso8601String(), 'cancel_reason' => $item->cancel_reason, 'executed_at' => $item->executed_at?->toIso8601String(), 'eligibility_snapshot' => $item->eligibility_snapshot];
    }
}
