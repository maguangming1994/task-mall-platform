<?php

namespace App\Modules\SecondHand\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecondHandOrderController extends Controller
{
    public function __construct(private readonly AssetLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'role' => ['required', Rule::in(['buyer', 'seller'])],
            'status' => ['nullable', 'string'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $user = $request->user();

        $query = SecondHandOrder::query()->with('item');
        if ($data['role'] === 'buyer') {
            $query->where('buyer_user_id', $user->id);
        } else {
            $query->where('seller_user_id', $user->id);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $query->latest('id');
        $total = $query->count();
        $orders = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $orders->map(fn (SecondHandOrder $o) => $this->present($o, $user->id)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id || $order->seller_user_id === $user->id, 404);
        $order->loadMissing(['item', 'buyer', 'seller']);

        return ApiResponse::success($this->present($order, $user->id, true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string'],
            'trade_type' => ['nullable', Rule::in(['meetup', 'mail'])],
            'address' => ['nullable', 'array'],
        ]);

        $item = SecondHandItem::where('public_id', $data['item_id'])->firstOrFail();
        abort_unless($item->status === 'on_sale', 409, '商品已售出或下架');
        $buyer = $request->user();
        abort_unless($item->seller_user_id !== $buyer->id, 400, '不能购买自己发布的商品');

        $order = DB::transaction(function () use ($item, $buyer, $data, $request) {
            $order = SecondHandOrder::create([
                'buyer_user_id' => $buyer->id,
                'seller_user_id' => $item->seller_user_id,
                'item_id' => $item->id,
                'title_snapshot' => $item->title,
                'cover_snapshot' => $item->cover_file_public_id,
                'price' => $item->price,
                'trade_type' => $data['trade_type'] ?? $item->trade_type === 'both' ? 'meetup' : ($item->trade_type === 'mail' ? 'mail' : $item->trade_type),
                'address_snapshot' => $data['address'] ?? null,
                'status' => 'pending_payment',
                'idempotency_key_hash' => hash('sha256', $request->header('Idempotency-Key') ?: ($request->ip().'|'.$buyer->id.'|'.$item->id)),
            ]);

            return $order;
        });
        $order->loadMissing(['item', 'buyer', 'seller']);

        return ApiResponse::success($this->present($order, $buyer->id, true), 201);
    }

    public function pay(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id, 403);
        abort_unless($order->status === 'pending_payment', 409, '订单状态不允许支付');

        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(['wallet'])],
        ]);

        DB::transaction(function () use ($order, $user, $data) {
            $locked = SecondHandOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending_payment', 409, '订单状态不允许支付');

            // 钱包余额扣款（余额不足时抛出 ValidationException，由全局异常处理器返回 422）
            $this->ledger->changeWallet(
                $user,
                -$locked->price,
                '二手闲置订单支付',
                'second-hand-order-payment:'.$locked->public_id,
                null,
                'second_hand_payment',
            );

            $locked->update([
                'status' => 'paid',
                'paid_amount' => $locked->price,
                'paid_at' => now(),
                'cash_payment_method' => $data['payment_method'] ?? 'wallet',
            ]);
            $locked->item()->update(['status' => 'reserved']);
        });
        $order->refresh();

        return ApiResponse::success($this->present($order, $user->id, true));
    }

    public function ship(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->seller_user_id === $user->id, 403);
        abort_unless($order->status === 'paid', 409, '订单状态不允许发货');

        $order->update(['status' => 'shipped', 'shipped_at' => now()]);

        return ApiResponse::success($this->present($order, $user->id, true));
    }

    public function complete(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id, 403);
        abort_unless(in_array($order->status, ['paid', 'shipped'], true), 409, '订单状态不允许确认收货');

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'completed', 'completed_at' => now()]);
            $order->item()->update(['status' => 'sold']);
        });
        $order->refresh();

        return ApiResponse::success($this->present($order, $user->id, true));
    }

    public function cancel(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id || $order->seller_user_id === $user->id, 403);
        abort_unless(in_array($order->status, ['pending_payment', 'paid'], true), 409, '订单状态不允许取消');

        DB::transaction(function () use ($order) {
            $locked = SecondHandOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['pending_payment', 'paid'], true), 409, '订单状态不允许取消');

            // 已支付订单取消时，全额退款到买家钱包
            if ($locked->status === 'paid' && $locked->paid_amount > 0) {
                $buyer = User::query()->findOrFail($locked->buyer_user_id);
                $this->ledger->changeWallet(
                    $buyer,
                    $locked->paid_amount,
                    '二手闲置订单取消退款',
                    'second-hand-order-refund:'.$locked->public_id,
                    null,
                    'second_hand_refund',
                );
            }

            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            if ($locked->item()->where('status', 'reserved')->exists()) {
                $locked->item()->update(['status' => 'on_sale']);
            }
        });
        $order->refresh();

        return ApiResponse::success($this->present($order, $user->id, true));
    }

    private function present(SecondHandOrder $order, int $viewerId, bool $detail = false): array
    {
        $role = $order->buyer_user_id === $viewerId ? 'buyer' : 'seller';
        $base = [
            'id' => $order->public_id,
            'order_no' => $order->order_no,
            'role' => $role,
            'status' => $order->status,
            'title' => $order->title_snapshot,
            'cover' => $order->cover_snapshot,
            'price' => round($order->price / 100, 2),
            'trade_type' => $order->trade_type,
            'item_id' => $order->item?->public_id,
            'counterparty' => $role === 'buyer' ? $this->presentUser($order->seller) : $this->presentUser($order->buyer),
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
        ];
        if ($detail) {
            $base['address'] = $order->address_snapshot;
            $base['buyer'] = $this->presentUser($order->buyer);
            $base['seller'] = $this->presentUser($order->seller);
        }

        return $base;
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->public_id, 'nickname' => $user->nickname, 'avatar' => $user->avatar_file_id];
    }
}
