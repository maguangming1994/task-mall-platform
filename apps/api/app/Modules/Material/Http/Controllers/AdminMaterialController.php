<?php

namespace App\Modules\Material\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialCategory;
use App\Modules\Material\Models\MaterialReport;
use App\Modules\Material\Models\MaterialRequest;
use App\Modules\Material\Services\MaterialQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminMaterialController extends Controller
{
    public function __construct(private readonly MaterialQuotaService $quota) {}

    // ---------- 分类管理 ----------

    public function categories(): JsonResponse
    {
        $items = MaterialCategory::query()
            ->withCount(['materials as material_count' => fn ($q) => $q->where('status', 'approved')])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MaterialCategory $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
                'sort_order' => (int) $c->sort_order,
                'status' => $c->status,
                'material_count' => (int) $c->material_count,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $category = MaterialCategory::query()->create([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? ((int) MaterialCategory::query()->max('sort_order') + 1),
            'status' => 'active',
        ]);

        return ApiResponse::success(['category' => [
            'id' => $category->public_id,
            'name' => $category->name,
            'sort_order' => (int) $category->sort_order,
            'status' => $category->status,
        ]], 201);
    }

    public function updateCategory(Request $request, MaterialCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);

        $category->update($data);

        return ApiResponse::success(['category' => [
            'id' => $category->public_id,
            'name' => $category->name,
            'sort_order' => (int) $category->sort_order,
            'status' => $category->status,
        ]]);
    }

    public function destroyCategory(MaterialCategory $category): JsonResponse
    {
        if ($category->materials()->exists()) {
            return ApiResponse::error('MATERIAL_CATEGORY_IN_USE', '该分类下已有资料，请先调整资料分类或停用分类。', 422);
        }
        $category->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    // ---------- 资料审核 ----------

    public function materials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'offline'])],
            'category_id' => ['nullable', 'string', 'max:40'],
            'keyword' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 10;

        $query = Material::query()->with(['category', 'uploader'])->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['category_id'])) {
            $query->whereHas('category', fn ($q) => $q->where('public_id', $data['category_id']));
        }
        if (! empty($data['keyword'])) {
            $kw = $data['keyword'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('uploader_name', 'like', "%{$kw}%"));
        }

        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (Material $m) => $this->presentForAdmin($m)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Material $material): JsonResponse
    {
        $material->loadMissing(['category', 'uploader', 'reviewer']);

        return ApiResponse::success($this->presentForAdmin($material));
    }

    public function review(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'offline'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['decision'] === 'reject' && empty($data['reason'])) {
            return ApiResponse::error('VALIDATION_ERROR', '驳回时必须填写原因。', 422, [
                'errors' => ['reason' => ['驳回时必须填写原因。']],
            ]);
        }

        $admin = $request->user();
        $wasPending = $material->status === 'pending';

        $material->update([
            'status' => match ($data['decision']) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'offline',
            },
            'reject_reason' => $data['decision'] === 'reject' ? $data['reason'] : null,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        // 首次审核通过且为真实用户投喂时，赠送长期获取额度（防重复发放）
        if ($data['decision'] === 'approve' && $wasPending && $material->uploader_user_id && ! $material->bonus_granted_at) {
            $uploader = User::query()->find($material->uploader_user_id);
            if ($uploader) {
                $this->quota->grantBonus($uploader);
                $material->forceFill(['bonus_granted_at' => now()])->save();
            }
        }

        $material->loadMissing(['category', 'uploader']);

        return ApiResponse::success(['material' => $this->presentForAdmin($material)]);
    }

    // ---------- 求资料需求 ----------

    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processing', 'done', 'closed'])],
            'keyword' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 10;

        $query = MaterialRequest::query()->with('user')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['keyword'])) {
            $kw = $data['keyword'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('requirement', 'like', "%{$kw}%"));
        }

        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (MaterialRequest $r) => [
                'id' => $r->public_id,
                'title' => $r->title,
                'requirement' => $r->requirement,
                'status' => $r->status,
                'admin_note' => $r->admin_note,
                'user' => $r->user ? ['id' => $r->user->public_id, 'nickname' => $r->user->nickname] : null,
                'created_at' => $r->created_at?->toIso8601String(),
                'handled_at' => $r->handled_at?->toIso8601String(),
            ]),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function updateRequest(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'processing', 'done', 'closed'])],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $materialRequest->update([
            'status' => $data['status'],
            'admin_note' => $data['admin_note'] ?? null,
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return ApiResponse::success(['request' => [
            'id' => $materialRequest->public_id,
            'status' => $materialRequest->status,
            'admin_note' => $materialRequest->admin_note,
        ]]);
    }

    // ---------- 举报处理 ----------

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'handled'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 10;

        $query = MaterialReport::query()->with('material')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (MaterialReport $r) => [
                'id' => $r->public_id,
                'material' => $r->material ? ['id' => $r->material->public_id, 'title' => $r->material->title] : null,
                'reason' => $r->reason,
                'detail' => $r->detail,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function handleReport(Request $request, MaterialReport $materialReport): JsonResponse
    {
        $materialReport->update([
            'status' => 'handled',
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return ApiResponse::success(['handled' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentForAdmin(Material $material): array
    {
        return [
            'id' => $material->public_id,
            'title' => $material->title,
            'description' => $material->description,
            'category' => $material->category ? [
                'id' => $material->category->public_id,
                'name' => $material->category->name,
            ] : null,
            'netdisk_type' => $material->netdisk_type,
            'netdisk_label' => Material::NETDISK_TYPES[$material->netdisk_type] ?? '其他资源',
            'share_url' => $material->share_url,
            'extract_code' => $material->extract_code,
            'share_text' => $material->share_text,
            'file_type' => $material->file_type,
            'source' => $material->source,
            'uploader_name' => $material->uploader_name ?: ($material->uploader?->nickname ?? '管理员'),
            'uploader' => $material->uploader ? ['id' => $material->uploader->public_id, 'nickname' => $material->uploader->nickname] : null,
            'status' => $material->status,
            'reject_reason' => $material->reject_reason,
            'bonus_granted' => $material->bonus_granted_at !== null,
            'view_count' => (int) $material->view_count,
            'fetch_count' => (int) $material->fetch_count,
            'created_at' => $material->created_at?->toIso8601String(),
            'reviewed_at' => $material->reviewed_at?->toIso8601String(),
        ];
    }
}
