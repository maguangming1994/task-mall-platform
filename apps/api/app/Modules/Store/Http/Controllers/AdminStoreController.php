<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreProduct;
use App\Modules\Store\Services\StoreCampusService;
use App\Modules\Store\Services\StoreCatalogService;
use App\Modules\Store\Services\StoreSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminStoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));
        $keyword = trim((string) $request->query('keyword', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('page_size', 20)));

        $paginator = Store::query()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where(function ($inner) use ($keyword): void {
                $inner->where('name', 'like', "%{$keyword}%")->orWhere('contact_phone', 'like', "%{$keyword}%");
            }))
            ->with('owner')
            ->withCount(['products' => fn ($query) => $query->where('status', StoreProduct::STATUS_ON_SALE)])
            ->latest('id')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (Store $store): array => $this->present($store))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Store $store, StoreSettlementService $settlements, StoreCampusService $campuses): JsonResponse
    {
        $store->load(['owner', 'members.user']);

        return ApiResponse::success([
            ...$this->present($store),
            'unbilled' => $settlements->unbilled($store),
            // 开通校区列表含停用记录，便于平台查看与重新启用
            'campuses' => $campuses->links($store)->map(fn ($link): array => [
                ...$campuses->present($link, $store),
                'unbilled' => $link->isActive() ? $settlements->unbilled($store, (int) $link->campus_id) : null,
            ])->values()->all(),
            'members' => $store->members->map(fn ($member): array => [
                'id' => $member->public_id,
                'role' => $member->role,
                'status' => $member->status,
                'nickname' => $member->user?->nickname,
                'phone' => $member->user?->phone,
                'joined_at' => $member->joined_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function review(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'commission_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ]);
        if ($data['action'] === 'approve' && trim((string) ($data['reason'] ?? '')) !== '') {
            // 通过时忽略填写的驳回原因，避免歧义
            $data['reason'] = null;
        }
        if ($data['action'] === 'reject' && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => ['驳回时必须填写原因。']]);
        }
        if ($store->status !== Store::STATUS_PENDING) {
            throw ValidationException::withMessages(['store' => ['只有待审核的门店可以审核。']]);
        }
        $store->update([
            'status' => $data['action'] === 'approve' ? Store::STATUS_ACTIVE : Store::STATUS_REJECTED,
            'reject_reason' => $data['action'] === 'reject' ? $data['reason'] : null,
            'commission_rate_bps' => $data['commission_rate_bps'] ?? $store->commission_rate_bps,
            'reviewed_at' => now(),
            'reviewed_by_admin_id' => $request->user()->id,
        ]);

        return ApiResponse::success($this->present($store->fresh('owner')), 200, $data['action'] === 'approve' ? '门店已通过审核。' : '门店申请已驳回。');
    }

    public function status(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:suspend,activate,close'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $target = match ($data['action']) {
            'suspend' => Store::STATUS_SUSPENDED,
            'activate' => Store::STATUS_ACTIVE,
            'close' => Store::STATUS_CLOSED,
            default => $store->status,
        };
        if ($target === Store::STATUS_ACTIVE && $store->status !== Store::STATUS_SUSPENDED) {
            throw ValidationException::withMessages(['action' => ['只有已暂停的门店可以恢复营业。']]);
        }
        $store->update(['status' => $target, 'reject_reason' => $data['reason'] ?? $store->reject_reason]);

        return ApiResponse::success($this->present($store->fresh('owner')), 200, '门店状态已更新。');
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'commission_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'shipping_fee_amount' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'free_shipping_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $store->update($data);

        return ApiResponse::success($this->present($store->fresh('owner')));
    }

    public function campusStatus(Request $request, Store $store, Campus $campus, StoreCampusService $campuses): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:enable,disable']]);
        $link = $campuses->setStatus($store, $campus, $data['action'], $request->user()->id);

        return ApiResponse::success($campuses->present($link, $store), 200, $data['action'] === 'enable' ? '校区已开通。' : '校区已停用。');
    }

    public function products(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));
        $keyword = trim((string) $request->query('keyword', ''));
        $storeId = trim((string) $request->query('store_id', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('page_size', 20)));

        $paginator = StoreProduct::query()
            ->whereHas('store')
            ->when($storeId !== '', fn ($query) => $query->whereHas('store', fn ($inner) => $inner->where('public_id', $storeId)))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
            ->with(['store', 'skus'])
            ->latest('id')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (StoreProduct $product): array => [
                'id' => $product->public_id,
                'name' => $product->name,
                'cover_url' => $product->cover_url,
                'status' => $product->status,
                'admin_off_shelf_reason' => $product->admin_off_shelf_reason,
                'store' => $product->store === null ? null : ['id' => $product->store->public_id, 'name' => $product->store->name],
                'sku_count' => $product->skus->count(),
                'price_from' => $product->skus->min('cash_amount'),
                'price_to' => $product->skus->max('cash_amount'),
                'created_at' => $product->created_at?->toIso8601String(),
            ])->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function productStatus(Request $request, StoreProduct $storeProduct, StoreCatalogService $catalog): JsonResponse
    {
        abort_unless(Store::query()->whereKey($storeProduct->store_id)->exists(), 404);
        $data = $request->validate([
            'action' => ['required', 'in:off_shelf,clear_block'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if ($data['action'] === 'off_shelf') {
            $product = $catalog->transition($storeProduct, 'off_shelf', $data['reason'] ?? '平台运营下架', null, $request->user()->id);
        } else {
            $product = $catalog->clearAdminBlock($storeProduct, $request->user()->id);
        }

        return ApiResponse::success([
            'id' => $product->public_id,
            'status' => $product->status,
            'admin_off_shelf_reason' => $product->admin_off_shelf_reason,
        ], 200, '商品状态已更新。');
    }

    /** @return array<string, mixed> */
    private function present(Store $store): array
    {
        return [
            'id' => $store->public_id,
            'name' => $store->name,
            'logo_url' => $store->logo_url,
            'description' => $store->description,
            'contact_phone' => $store->contact_phone,
            'address' => $store->address,
            'business_hours' => $store->business_hours,
            'status' => $store->status,
            'reject_reason' => $store->reject_reason,
            'commission_rate_bps' => (int) $store->commission_rate_bps,
            'shipping_fee_amount' => (int) $store->shipping_fee_amount,
            'free_shipping_threshold' => $store->free_shipping_threshold === null ? null : (int) $store->free_shipping_threshold,
            'campus_id' => $store->campus_id,
            'campus_name' => $store->campus_id === null ? null : Campus::query()->whereKey($store->campus_id)->value('name'),
            'owner' => $store->owner === null ? null : [
                'id' => $store->owner->public_id,
                'nickname' => $store->owner->nickname,
                'phone' => $store->owner->phone,
                'status' => $store->owner->status,
            ],
            'product_count' => (int) ($store->products_count ?? 0),
            'applied_at' => $store->applied_at?->toIso8601String(),
            'reviewed_at' => $store->reviewed_at?->toIso8601String(),
            'created_at' => $store->created_at?->toIso8601String(),
        ];
    }
}
