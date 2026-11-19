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
use App\Modules\Material\Services\NetdiskShareParser;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminMaterialController extends Controller
{
    private const FILE_TYPES = ['pdf', 'word', 'excel', 'ppt', 'video', 'archive', 'other'];

    public function __construct(
        private readonly MaterialQuotaService $quota,
        private readonly NetdiskShareParser $parser,
        private readonly NotificationService $notifications,
    ) {}

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

    // ---------- 资料补录与编辑 ----------

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validateMaterialPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }
        $admin = $request->user();

        $material = Material::query()->create([
            'campus_id' => null, // 平台级资料，对所有校区可见
            'category_id' => $payload['category_id'],
            'uploader_user_id' => null,
            'uploader_name' => '管理员',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'netdisk_type' => $payload['netdisk_type'],
            'share_url' => $payload['share_url'],
            'extract_code' => $payload['extract_code'],
            'share_text' => $payload['share_text'],
            'file_type' => $payload['file_type'],
            'source' => 'admin',
            'status' => $payload['status'],
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        $material->loadMissing('category');

        return ApiResponse::success(['material' => $this->presentForAdmin($material)], 201);
    }

    public function update(Request $request, Material $material): JsonResponse
    {
        $payload = $this->validateMaterialPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $material->update([
            'category_id' => $payload['category_id'],
            'title' => $payload['title'],
            'description' => $payload['description'],
            'netdisk_type' => $payload['netdisk_type'],
            'share_url' => $payload['share_url'],
            'extract_code' => $payload['extract_code'],
            'share_text' => $payload['share_text'],
            'file_type' => $payload['file_type'],
            'status' => $payload['status'],
        ]);
        $material->loadMissing('category');

        return ApiResponse::success(['material' => $this->presentForAdmin($material)]);
    }

    /**
     * 统一解析补录/编辑表单：支持粘贴分享文案自动解析，也可直接填写链接。
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validateMaterialPayload(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['nullable', 'string', 'max:40'],
            'share_text' => ['nullable', 'string', 'max:8000'],
            'share_url' => ['nullable', 'url', 'max:500'],
            'extract_code' => ['nullable', 'string', 'max:40'],
            'netdisk_type' => ['nullable', Rule::in(array_keys(Material::NETDISK_TYPES))],
            'file_type' => ['nullable', Rule::in(self::FILE_TYPES)],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'offline'])],
        ]);

        $parsed = ! empty($data['share_text']) ? $this->parser->parse($data['share_text']) : [];
        $shareUrl = $data['share_url'] ?? ($parsed['share_url'] ?? null);
        if (empty($shareUrl)) {
            return ApiResponse::error('MATERIAL_PARSE_FAILED', '请粘贴完整分享文案，或直接填写分享链接。', 422);
        }

        $categoryId = null;
        if (! empty($data['category_id'])) {
            $categoryId = MaterialCategory::query()->where('public_id', $data['category_id'])->value('id');
            if ($categoryId === null) {
                return ApiResponse::error('MATERIAL_CATEGORY_NOT_FOUND', '所选分类不存在。', 422);
            }
        }

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category_id' => $categoryId,
            'share_url' => $shareUrl,
            'extract_code' => $data['extract_code'] ?? ($parsed['extract_code'] ?? null),
            'share_text' => $data['share_text'] ?? null,
            'netdisk_type' => $data['netdisk_type'] ?? ($parsed['netdisk_type'] ?? 'other'),
            'file_type' => $data['file_type'] ?? ($parsed['file_type'] ?? 'other'),
            'status' => $data['status'] ?? 'approved',
        ];
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

        // 审核与发放奖励必须原子完成：行锁资料状态，避免并发审核重复发放；
        // 锁投稿用户行，保证同一用户的奖励判定串行执行。
        $reviewed = DB::transaction(function () use ($material, $data, $admin): Material {
            $locked = Material::query()->lockForUpdate()->findOrFail($material->getKey());
            $wasPending = $locked->status === 'pending';

            $locked->update([
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
            if ($data['decision'] === 'approve' && $wasPending && $locked->uploader_user_id && ! $locked->bonus_granted_at) {
                $uploader = User::query()->lockForUpdate()->find($locked->uploader_user_id);
                if ($uploader) {
                    // 同一用户重复投喂同一网盘链接时只奖励一次
                    $alreadyRewarded = Material::query()
                        ->where('uploader_user_id', $locked->uploader_user_id)
                        ->where('share_url', $locked->share_url)
                        ->whereNotNull('bonus_granted_at')
                        ->whereKeyNot($locked->getKey())
                        ->exists();

                    if (! $alreadyRewarded) {
                        $this->quota->grantBonus($uploader);
                        $locked->forceFill(['bonus_granted_at' => now()])->save();
                    }
                }
            }

            // 审核结果通知投稿人（平台补录资料无投稿人时跳过）；
            // 用 eventKey 保证同一资料同一结论重复审核只通知一次。
            $locked->loadMissing('uploader');
            if ($locked->uploader) {
                $reason = $data['reason'] ?? null;
                match ($data['decision']) {
                    'approve' => $this->notifications->create(
                        $locked->uploader,
                        'material',
                        'material_review_approved',
                        '资料审核通过',
                        '你分享的资料《'.$locked->title.'》已通过审核并已上架，感谢你的分享。',
                        'material-review-approved:'.$locked->getKey(),
                        data: ['material_id' => $locked->public_id],
                    ),
                    'reject' => $this->notifications->create(
                        $locked->uploader,
                        'material',
                        'material_review_rejected',
                        '资料审核未通过',
                        '你分享的资料《'.$locked->title.'》未通过审核：'.($reason ?: '请核对内容后重新提交。'),
                        'material-review-rejected:'.$locked->getKey(),
                        data: ['material_id' => $locked->public_id],
                    ),
                    default => $this->notifications->create(
                        $locked->uploader,
                        'material',
                        'material_takedown',
                        '你分享的资料已被下架',
                        '你分享的资料《'.$locked->title.'》已被管理员下架。'.($reason ? '原因：'.$reason : '如有疑问可联系平台客服。'),
                        'material-review-offline:'.$locked->getKey(),
                        data: ['material_id' => $locked->public_id],
                    ),
                };
            }

            return $locked;
        });

        $reviewed->loadMissing(['category', 'uploader']);

        return ApiResponse::success(['material' => $this->presentForAdmin($reviewed)]);
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

        $oldStatus = $materialRequest->status;
        $oldNote = $materialRequest->admin_note;
        $newNote = $data['admin_note'] ?? null;

        $materialRequest->update([
            'status' => $data['status'],
            'admin_note' => $newNote,
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        // 仅当状态或回复备注有实质变化时通知发起人，避免重复操作重复打扰；
        // eventKey 含状态与备注哈希，保证每次新回复都能送达、相同内容重复提交被去重。
        if (($oldStatus !== $data['status'] || $oldNote !== $newNote) && $materialRequest->user) {
            $statusLabel = match ($data['status']) {
                'processing' => '处理中',
                'done' => '已完成',
                'closed' => '已关闭',
                default => '待处理',
            };
            $noteText = $newNote !== null && $newNote !== '' ? '回复：'.$newNote : '（暂无回复说明）';
            $this->notifications->create(
                $materialRequest->user,
                'material',
                'material_request_replied',
                '求资料需求有新回复',
                '你提交的求资料需求《'.$materialRequest->title.'》状态更新为「'.$statusLabel.'」，'.$noteText,
                'material-request-reply:'.$materialRequest->public_id.':'.$data['status'].':'.md5((string) $newNote),
                data: ['material_request_id' => $materialRequest->public_id, 'material_title' => $materialRequest->title],
            );
        }

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

        $query = MaterialReport::query()->with(['material.category', 'user'])->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (MaterialReport $r) => [
                'id' => $r->public_id,
                'material' => $r->material ? [
                    'id' => $r->material->public_id,
                    'title' => $r->material->title,
                    'status' => $r->material->status,
                    'netdisk_type' => $r->material->netdisk_type,
                    'netdisk_label' => Material::NETDISK_TYPES[$r->material->netdisk_type] ?? '其他资源',
                    'share_url' => $r->material->share_url,
                    'extract_code' => $r->material->extract_code,
                    'fetch_count' => (int) $r->material->fetch_count,
                    'uploader_name' => $r->material->uploader_name ?: ($r->material->uploader?->nickname ?? '管理员'),
                ] : null,
                'reporter' => $r->user ? ['id' => $r->user->public_id, 'nickname' => $r->user->nickname] : null,
                'reason' => $r->reason,
                'detail' => $r->detail,
                'status' => $r->status,
                'decision' => $r->decision,
                'handling_note' => $r->handling_note,
                'created_at' => $r->created_at?->toIso8601String(),
                'handled_at' => $r->handled_at?->toIso8601String(),
            ]),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    /**
     * 举报处理闭环：记录处理结论与备注，可联动下架资料，
     * 并通知举报人 / 资料作者；同一资料的其余待处理举报一并结案。
     */
    public function handleReport(Request $request, MaterialReport $materialReport): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'dismissed', 'other'])],
            'handling_note' => ['nullable', 'string', 'max:500'],
            'takedown' => ['nullable', 'boolean'],
        ]);
        $admin = $request->user();
        $decision = $data['decision'];
        $note = $data['handling_note'] ?? null;
        $takedown = (bool) ($data['takedown'] ?? false) || $decision === 'upheld';

        $result = DB::transaction(function () use ($materialReport, $admin, $decision, $note, $takedown): array {
            // 锁住当前举报与资料，避免并发处理
            $report = MaterialReport::query()->lockForUpdate()->findOrFail($materialReport->getKey());

            // 幂等：已处理过的举报不再重复执行下架/通知
            if ($report->status === 'handled') {
                return ['handled' => false, 'idempotent' => true];
            }

            $material = $report->material;

            // 成立 / 主动下架：将资料置为下架，处理备注作为下架原因
            if ($takedown && $material && $material->status !== 'offline') {
                $lockedMaterial = Material::query()->lockForUpdate()->findOrFail($material->getKey());
                $lockedMaterial->update([
                    'status' => 'offline',
                    'reject_reason' => $note ?: '因侵权/违规举报已下架。',
                ]);
                $material = $lockedMaterial;

                // 通知资料作者（平台补录资料无作者则跳过）
                if ($material->uploader) {
                    $this->notifications->create(
                        $material->uploader,
                        'material',
                        'material_takedown',
                        '你分享的资料已被下架',
                        '你的资料《'.$material->title.'》因举报处理已被下架。原因：'.($note ?: '侵权或违规内容。'),
                        'material-report-takedown:'.$material->getKey(),
                        data: ['material_id' => $material->public_id],
                    );
                }
            }

            // 同一资料的其它待处理举报一并结案（结论同步），避免重复处理
            $siblingIds = [];
            if ($material) {
                $siblingIds = MaterialReport::query()
                    ->where('material_id', $material->getKey())
                    ->where('status', 'pending')
                    ->whereKeyNot($report->getKey())
                    ->pluck('id')->all();

                if (! empty($siblingIds)) {
                    MaterialReport::query()->whereIn('id', $siblingIds)->update([
                        'status' => 'handled',
                        'decision' => $decision,
                        'handling_note' => $note,
                        'handled_by' => $admin->id,
                        'handled_at' => now(),
                    ]);
                }
            }

            // 标记当前举报已处理
            $report->update([
                'status' => 'handled',
                'decision' => $decision,
                'handling_note' => $note,
                'handled_by' => $admin->id,
                'handled_at' => now(),
            ]);

            // 反馈所有相关举报人（当前 + 一并结案的）处理结果
            $resolvedIds = array_merge([$report->getKey()], $siblingIds);
            $resolvedReports = MaterialReport::query()->with('user')->whereIn('id', $resolvedIds)->get();
            $msg = match ($decision) {
                'upheld' => '你的举报已受理，相关资料已下架。感谢你的反馈。',
                'dismissed' => '你的举报已处理，经核实暂不构成违规。感谢你的反馈。',
                default => '你的举报已处理。感谢你的反馈。',
            };
            foreach ($resolvedReports as $resolved) {
                if (! $resolved->user) {
                    continue;
                }
                $this->notifications->create(
                    $resolved->user,
                    'material',
                    'material_report_result',
                    '举报处理结果',
                    $msg,
                    'material-report-resolved:'.$resolved->getKey(),
                    data: ['report_id' => $resolved->public_id],
                );
            }

            return ['handled' => true, 'idempotent' => false, 'resolved_count' => count($resolvedIds)];
        });

        return ApiResponse::success($result);
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
