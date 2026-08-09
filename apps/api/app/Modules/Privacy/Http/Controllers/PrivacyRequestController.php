<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Services\PrivacyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyRequestController extends Controller
{
    public function index(Request $request, PrivacyRequestService $privacy): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $items = PrivacyRequest::query()->where('user_id', $user->id)->latest('id')->get()->map(fn ($item) => $privacy->present($item));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function store(Request $request, PrivacyRequestService $privacy): JsonResponse
    {
        $data = $request->validate([
            'request_type' => ['required', 'in:access,copy,correction,deletion,withdraw_consent'],
            'scope' => ['required', 'array', 'min:1'], 'scope.*' => ['string', 'in:profile,identities,devices,tasks,community,orders,assets,consents,all_available'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 12 || strlen($key) > 128) {
            return ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', '请提供 12 至 128 字符的幂等键。', 422);
        }
        /** @var User $user */ $user = $request->user();
        $created = $privacy->create($user, $data, $key);

        return ApiResponse::success($privacy->present($created), 201, '隐私权利请求已提交。');
    }
}
