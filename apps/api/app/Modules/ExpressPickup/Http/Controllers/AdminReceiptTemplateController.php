<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\ExpressPickup\Services\ReceiptTemplateService;
use App\Modules\ExpressPickup\Support\ReceiptTemplateCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReceiptTemplateController extends Controller
{
    public function __construct(private readonly ReceiptTemplateService $templates) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->templates->overview());
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateTemplate($request);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $template = $this->templates->save(null, $data, $admin);
        $audit->record($admin, 'receipt_template.create', 'system_setting', ReceiptTemplateService::SETTING_KEY, null, $template, '新建小票模板。');

        return ApiResponse::success([
            'template' => $template,
            'overview' => $this->templates->overview(),
        ], 201, '小票模板已创建。');
    }

    public function update(Request $request, string $receiptTemplate, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateTemplate($request);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $template = $this->templates->save($receiptTemplate, $data, $admin);
        $audit->record($admin, 'receipt_template.update', 'system_setting', ReceiptTemplateService::SETTING_KEY, null, $template, '保存小票模板。');

        return ApiResponse::success([
            'template' => $template,
            'overview' => $this->templates->overview(),
        ], 200, '小票模板已保存。');
    }

    public function duplicate(Request $request, string $receiptTemplate, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $template = $this->templates->duplicate($receiptTemplate, $admin);
        $audit->record($admin, 'receipt_template.duplicate', 'system_setting', ReceiptTemplateService::SETTING_KEY, null, $template, '复制小票模板。');

        return ApiResponse::success([
            'template' => $template,
            'overview' => $this->templates->overview(),
        ], 201, '小票模板已复制。');
    }

    public function toggle(Request $request, string $receiptTemplate, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $template = $this->templates->toggle($receiptTemplate, $admin);
        $audit->record(
            $admin,
            'receipt_template.toggle',
            'system_setting',
            ReceiptTemplateService::SETTING_KEY,
            null,
            $template,
            $template['status'] === 'enabled' ? '启用小票模板。' : '停用小票模板。',
        );

        return ApiResponse::success([
            'template' => $template,
            'overview' => $this->templates->overview(),
        ], 200, $template['status'] === 'enabled' ? '小票模板已启用。' : '小票模板已停用。');
    }

    public function destroy(Request $request, string $receiptTemplate, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $this->templates->remove($receiptTemplate, $admin);
        $audit->record($admin, 'receipt_template.delete', 'system_setting', ReceiptTemplateService::SETTING_KEY, ['id' => $receiptTemplate], null, '删除小票模板。');

        return ApiResponse::success(['id' => $receiptTemplate], 200, '小票模板已删除。');
    }

    public function updateBindings(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'bindings' => ['required', 'array'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $overview = $this->templates->saveBindings($data['bindings'], $admin);
        $audit->record($admin, 'receipt_template.bindings', 'system_setting', ReceiptTemplateService::SETTING_KEY, null, $data['bindings'], '调整打印模板绑定。');

        return ApiResponse::success($overview, 200, '打印模板绑定已保存。');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'blocks' => ['present', 'array', 'max:40'],
            'paper_width' => ['nullable', 'integer', Rule::in([58, 80])],
        ]);
        $result = $this->templates->preview($data['blocks'], (int) ($data['paper_width'] ?? 58));

        return ApiResponse::success($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'paper_width' => ['nullable', 'integer', Rule::in([58, 80])],
            'print_type' => ['nullable', Rule::in(array_keys(ReceiptTemplateService::SCENES))],
            'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
            'blocks' => ['present', 'array', 'max:40'],
            'blocks.*.id' => ['nullable', 'string', 'max:64'],
            'blocks.*.type' => ['required', Rule::in(array_keys(ReceiptTemplateCompiler::BLOCK_TYPES))],
            'blocks.*.props' => ['nullable', 'array'],
            'blocks.*.props.content' => ['nullable', 'string', 'max:500'],
            'blocks.*.props.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'blocks.*.props.font_size' => ['nullable', 'integer', 'between:0,2'],
            'blocks.*.props.bold' => ['nullable', 'integer', 'between:0,2'],
        ]);
    }
}
