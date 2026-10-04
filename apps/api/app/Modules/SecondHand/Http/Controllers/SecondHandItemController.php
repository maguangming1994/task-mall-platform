<?php

namespace App\Modules\SecondHand\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\SecondHand\Models\SecondHandCategory;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\SecondHand\Services\SecondHandNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecondHandItemController extends Controller
{
    public function __construct(private readonly SecondHandNotificationService $notify) {}

    private const CONDITIONS = [
        'new' => '全新',
        'like_new' => '几乎全新',
        'very_good' => '轻微使用',
        'good' => '正常使用',
        'fair' => '明显使用',
    ];

    public function categories(): JsonResponse
    {
        $items = SecondHandCategory::query()->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SecondHandCategory $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
                'icon' => $c->icon,
                'sort_order' => (int) $c->sort_order,
            ]);

        return ApiResponse::success(['items' => $items, 'conditions' => self::CONDITIONS]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'string'],
            'keyword' => ['nullable', 'string', 'max:60'],
            'condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'trade_type' => ['nullable', Rule::in(['meetup', 'mail', 'both'])],
            'seller' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', Rule::in(['latest', 'price_asc', 'price_desc'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandItem::query()->where('status', 'on_sale')->where('audit_status', 'approved');
        if (! empty($data['category_id'])) {
            $query->whereHas('category', fn ($q) => $q->where('public_id', $data['category_id']));
        }
        if (! empty($data['keyword'])) {
            $kw = $data['keyword'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('description', 'like', "%{$kw}%"));
        }
        if (! empty($data['condition'])) {
            $query->where('condition', $data['condition']);
        }
        if (! empty($data['trade_type'])) {
            $tt = $data['trade_type'];
            $query->where(fn ($q) => $q->where('trade_type', $tt)->orWhere('trade_type', 'both'));
        }

        if (! empty($data['seller'])) {
            $query->whereHas('seller', fn ($q) => $q->where('public_id', $data['seller']));
        }

        $sort = $data['sort'] ?? 'latest';
        if ($sort === 'price_asc') {
            $query->orderBy('price');
        } elseif ($sort === 'price_desc') {
            $query->orderByDesc('price');
        } else {
            $query->latest('id');
        }

        $total = $query->count();
        $items = $query->forPage($page, $size)->with('seller')->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->present($i)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless(in_array($item->status, ['on_sale', 'reserved', 'sold'], true), 404);
        $item->increment('view_count');
        $item->loadMissing(['seller', 'category']);
        $isOwner = $request->user()?->id === $item->seller_user_id;

        // 非本人查看时，审核不通过的商品不可见
        abort_unless($isOwner || $item->audit_status === 'approved', 404);

        return ApiResponse::success($this->present($item, true, $isOwner));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateInput($request);
        $user = $request->user();
        $item = DB::transaction(function () use ($user, $data, $request) {
            return SecondHandItem::create([
                'campus_id' => $user->campus_id,
                'seller_user_id' => $user->id,
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'price' => (int) round(($data['price'] ?? 0) * 100),
                'condition' => $data['condition'] ?? 'like_new',
                'cover_file_public_id' => $data['cover'] ?? null,
                'images' => $data['images'] ?? [],
                'trade_type' => $data['trade_type'] ?? 'meetup',
                'location' => $data['location'] ?? null,
                'status' => 'on_sale',
                'audit_status' => 'pending',
                'idempotency_key_hash' => hash('sha256', $request->header('Idempotency-Key') ?: ($request->ip().'|'.$user->id.'|'.microtime(true))),
            ]);
        });
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true), 201);
    }

    public function update(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        abort_unless(in_array($item->status, ['on_sale', 'reserved'], true), 409, '已售出或已下架的商品不能编辑');
        $data = $this->validateInput($request);

        $item->update([
            'category_id' => $data['category_id'] ?? $item->category_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => (int) round(($data['price'] ?? 0) * 100),
            'condition' => $data['condition'] ?? 'like_new',
            'cover_file_public_id' => $data['cover'] ?? $item->cover_file_public_id,
            'images' => $data['images'] ?? $item->images,
            'trade_type' => $data['trade_type'] ?? 'meetup',
            'location' => $data['location'] ?? null,
        ]);
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true));
    }

    public function destroy(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        $item->update(['status' => 'off_shelf']);

        return ApiResponse::success(['id' => $item->public_id, 'status' => 'off_shelf']);
    }

    public function relist(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        abort_unless($item->status === 'off_shelf', 409, '仅已下架的商品可以重新上架');

        // 曾通过审核的直接恢复在售；被驳回或未审核过的重新进入审核队列
        $auditStatus = $item->audit_status === 'approved' ? 'approved' : 'pending';
        $item->update(['status' => 'on_sale', 'audit_status' => $auditStatus]);
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true));
    }

    public function sellerProfile(string $user): JsonResponse
    {
        $seller = User::query()->where('public_id', $user)->firstOrFail();

        $onSaleCount = SecondHandItem::query()
            ->where('seller_user_id', $seller->id)
            ->where('status', 'on_sale')->where('audit_status', 'approved')->count();
        $soldCount = SecondHandItem::query()
            ->where('seller_user_id', $seller->id)->where('status', 'sold')->count();

        $ratingQuery = SecondHandRating::query()->where('ratee_user_id', $seller->id);
        $ratingCount = (clone $ratingQuery)->count();
        $average = $ratingCount > 0 ? round((float) (clone $ratingQuery)->avg('score'), 1) : null;
        $goodCount = (clone $ratingQuery)->where('score', '>=', 4)->count();
        $goodRate = $ratingCount > 0 ? (int) round($goodCount / $ratingCount * 100) : null;

        $identityVerified = UserIdentityVerification::query()
            ->where('user_id', $seller->id)->where('status', 'verified')->exists();

        return ApiResponse::success([
            'id' => $seller->public_id,
            'nickname' => $seller->nickname,
            'avatar' => $seller->avatar_file_id,
            'campus_name' => $seller->campus?->name,
            'identity_verified' => $identityVerified,
            'on_sale_count' => $onSaleCount,
            'sold_count' => $soldCount,
            'rating_average' => $average,
            'rating_count' => $ratingCount,
            'good_rate_percent' => $goodRate,
        ]);
    }

    // ---------- Admin 审核 ----------

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audit_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandItem::query()
            ->when($data['audit_status'] ?? null, fn ($q, $s) => $q->where('audit_status', $s))
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->with('seller')->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->present($i)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function adminReview(Request $request, SecondHandItem $item): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['decision'] === 'approve') {
            $item->update(['audit_status' => 'approved', 'rejected_reason' => null]);
        } else {
            $item->update(['audit_status' => 'rejected', 'rejected_reason' => $data['reason'] ?? '未通过审核']);
        }
        $this->notify->itemReviewed($item, $data['decision'] === 'approve', $data['reason'] ?? null);

        return ApiResponse::success($this->present($item, true));
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', Rule::in(['on_sale', 'reserved', 'sold', 'off_shelf'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = SecondHandItem::query()->where('seller_user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->present($i)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'category_id' => ['nullable', 'string'],
            'cover' => ['nullable', 'string', 'max:26'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:26'],
            'trade_type' => ['nullable', Rule::in(['meetup', 'mail', 'both'])],
            'location' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function present(SecondHandItem $item, bool $detail = false, bool $isOwner = false): array
    {
        $base = [
            'id' => $item->public_id,
            'title' => $item->title,
            'price' => round($item->price / 100, 2),
            'price_fen' => $item->price,
            'condition' => $item->condition,
            'condition_label' => self::CONDITIONS[$item->condition] ?? $item->condition,
            'cover' => $item->cover_file_public_id,
            'images' => $item->images ?? [],
            'status' => $item->status,
            'audit_status' => $item->audit_status,
            'rejected_reason' => $item->rejected_reason,
            'trade_type' => $item->trade_type,
            'location' => $item->location,
            'view_count' => (int) $item->view_count,
            'created_at' => $item->created_at?->toIso8601String(),
            'category' => $item->category ? ['id' => $item->category->public_id, 'name' => $item->category->name] : null,
            'seller' => $this->presentUser($item->seller),
        ];

        if ($detail) {
            $base['description'] = $item->description;
            $base['favorite_count'] = (int) $item->favorite_count;
            $base['is_owner'] = $isOwner;
        }

        return $base;
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar_file_id,
        ];
    }
}
