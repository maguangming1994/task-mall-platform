<?php

namespace App\Modules\Material\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialCategory;
use App\Modules\Material\Models\MaterialFetch;
use App\Modules\Material\Models\MaterialReport;
use App\Modules\Material\Services\MaterialQuotaService;
use App\Modules\Material\Services\NetdiskShareParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function __construct(
        private readonly NetdiskShareParser $parser,
        private readonly MaterialQuotaService $quota,
    ) {}

    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $categories = MaterialCategory::query()->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MaterialCategory $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
                'sort_order' => (int) $c->sort_order,
            ]);

        return ApiResponse::success([
            'categories' => $categories,
            'stats' => ['total' => Material::query()->where('status', 'approved')->count()],
            'daily_limit' => $this->quota->dailyLimit(),
            'bonus_per_approved' => $this->quota->bonusPerApproved(),
            'quota' => $user ? $this->quota->summary($user) : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'string', 'max:40'],
            'keyword' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', Rule::in(['latest', 'hot'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = Material::query()->where('status', 'approved');
        if (! empty($data['category_id'])) {
            $query->whereHas('category', fn ($q) => $q->where('public_id', $data['category_id']));
        }
        if (! empty($data['keyword'])) {
            $kw = $data['keyword'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('description', 'like', "%{$kw}%"));
        }

        if (($data['sort'] ?? 'latest') === 'hot') {
            $query->orderByDesc('fetch_count')->orderByDesc('id');
        } else {
            $query->latest('id');
        }

        $total = $query->count();
        $items = $query->forPage($page, $size)->with(['category', 'uploader'])->get();

        return ApiResponse::success([
            'items' => $items->map(fn (Material $m) => $this->present($m)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, Material $material): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user && $user->id === $material->uploader_user_id;
        abort_unless($isOwner || $material->status === 'approved', 404);

        $material->increment('view_count');
        $material->loadMissing(['category', 'uploader']);

        $payload = $this->present($material, $isOwner);
        $payload['is_owner'] = (bool) $isOwner;
        $payload['already_fetched'] = $user
            ? MaterialFetch::query()->where('material_id', $material->id)->where('user_id', $user->id)->exists()
            : false;

        return ApiResponse::success($payload);
    }

    /** 获取网盘地址：消耗一次额度（同一资料重复获取不再消耗） */
    public function fetch(Request $request, Material $material): JsonResponse
    {
        abort_unless($material->status === 'approved', 404);
        $user = $request->user();

        $result = $this->quota->consume($user, $material);
        if ($result === null) {
            return ApiResponse::error(
                'MATERIAL_QUOTA_EXHAUSTED',
                '今日获取额度已用完，投喂资料审核通过可获赠长期额度。',
                422,
                ['quota' => $this->quota->summary($user)],
            );
        }

        return ApiResponse::success([
            'share_url' => $material->share_url,
            'extract_code' => $material->extract_code,
            'netdisk_type' => $material->netdisk_type,
            'netdisk_label' => Material::NETDISK_TYPES[$material->netdisk_type] ?? '其他资源',
            'already_owned' => $result['already_owned'],
            'quota' => $this->quota->summary($user),
        ]);
    }

    /** 投喂资料（提交后进入审核） */
    public function store(Request $request): JsonResponse
    {
        $maxLength = (int) config('material.max_share_text_length', 8000);
        $data = $request->validate([
            'share_text' => ['required', 'string', 'min:5', 'max:'.$maxLength],
            'title' => ['nullable', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['nullable', 'string', 'max:40'],
            'agreement_accepted' => ['accepted'],
        ]);

        $parsed = $this->parser->parse($data['share_text']);
        if (empty($parsed['share_url'])) {
            return ApiResponse::error('MATERIAL_PARSE_FAILED', '未能识别到网盘链接，请粘贴完整分享文案。', 422);
        }

        $categoryId = null;
        if (! empty($data['category_id'])) {
            $categoryId = MaterialCategory::query()->where('public_id', $data['category_id'])->value('id');
        }

        $user = $request->user();
        $material = Material::query()->create([
            'campus_id' => $user->campus_id,
            'category_id' => $categoryId,
            'uploader_user_id' => $user->id,
            'uploader_name' => $user->nickname,
            'title' => $data['title'] ?? $parsed['title'],
            'description' => $data['description'] ?? null,
            'netdisk_type' => $parsed['netdisk_type'],
            'share_url' => $parsed['share_url'],
            'extract_code' => $parsed['extract_code'],
            'share_text' => $data['share_text'],
            'file_type' => $parsed['file_type'],
            'source' => 'user',
            'status' => 'pending',
        ]);
        $material->loadMissing('category');

        return ApiResponse::success([
            'material' => $this->present($material, true),
            'parsed' => $parsed,
            'review_note' => '资料需审核通过后展示，审核通过将赠送长期获取额度。',
        ], 201);
    }

    /** 我的投喂 */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = Material::query()->where('uploader_user_id', $request->user()->id)->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->with('category')->get();

        return ApiResponse::success([
            'items' => $items->map(fn (Material $m) => array_merge($this->present($m, true), [
                'reject_reason' => $m->reject_reason,
            ])),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    /** 侵权举报 */
    public function report(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'detail' => ['nullable', 'string', 'max:500'],
        ]);

        MaterialReport::query()->firstOrCreate(
            [
                'material_id' => $material->id,
                'user_id' => $request->user()->id,
                'status' => 'pending',
            ],
            [
                'reason' => $data['reason'],
                'detail' => $data['detail'] ?? null,
            ],
        );

        return ApiResponse::success(['message' => '举报已提交，我们会尽快核实处理。']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Material $material, bool $detail = false): array
    {
        $payload = [
            'id' => $material->public_id,
            'title' => $material->title,
            'description' => $material->description,
            'category' => $material->category ? [
                'id' => $material->category->public_id,
                'name' => $material->category->name,
            ] : null,
            'netdisk_type' => $material->netdisk_type,
            'netdisk_label' => Material::NETDISK_TYPES[$material->netdisk_type] ?? '其他资源',
            'file_type' => $material->file_type,
            'source' => $material->source,
            'uploader_name' => $material->uploader_name ?: ($material->uploader?->nickname ?? '管理员'),
            'is_admin_upload' => $material->source === 'admin' || $material->uploader_user_id === null,
            'status' => $material->status,
            'view_count' => (int) $material->view_count,
            'fetch_count' => (int) $material->fetch_count,
            'created_at' => $material->created_at?->toIso8601String(),
        ];

        if ($detail) {
            $payload['share_url'] = $material->share_url;
            $payload['extract_code'] = $material->extract_code;
            $payload['reject_reason'] = $material->reject_reason;
        }

        return $payload;
    }
}
