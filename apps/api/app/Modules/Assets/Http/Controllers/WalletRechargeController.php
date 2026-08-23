<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assets\Models\WalletRecharge;
use App\Modules\Assets\Services\WalletRechargeService;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletRechargeController extends Controller
{
    public function index(Request $request, WalletRechargeService $service): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $items = WalletRecharge::query()->where('user_id', $user->id)->latest('id')->limit(50)->get()->map(fn ($item) => $service->present($item));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request, WalletRechargeService $service): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:card_code,online,offline'],
            'amount_cents' => ['required_unless:method,card_code', 'nullable', 'integer', 'min:100', 'max:5000000'],
            'card_code' => ['required_if:method,card_code', 'nullable', 'string', 'min:12', 'max:80'],
            'payer_name' => ['required_if:method,offline', 'nullable', 'string', 'min:2', 'max:80'],
            'offline_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'offline_proof_file_ids' => ['required_if:method,offline', 'array', 'min:1', 'max:6'],
            'offline_proof_file_ids.*' => ['string', 'size:26'],
        ]);
        /** @var User $user */ $user = $request->user();
        $recharge = $service->create($user, $data, $this->key($request));

        return ApiResponse::success($service->present($recharge), 201, '充值申请已创建。');
    }

    public function show(Request $request, WalletRecharge $walletRecharge, WalletRechargeService $service): JsonResponse
    {
        abort_unless($walletRecharge->user_id === $request->user()->id, 404);

        return ApiResponse::success($service->present($walletRecharge));
    }

    public function pay(Request $request, WalletRecharge $walletRecharge, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['scene' => ['required', 'in:wechat-mini,wechat-h5,h5-browser']]);
        /** @var User $user */ $user = $request->user();

        return ApiResponse::success($payments->present($payments->createRecharge($walletRecharge, $user, $data['scene'], $this->key($request))), 201);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, '请提供有效的幂等键。');

        return $key;
    }
}
