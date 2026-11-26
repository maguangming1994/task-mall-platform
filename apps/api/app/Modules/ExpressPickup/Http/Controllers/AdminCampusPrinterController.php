<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Printers\PrinterManager;
use App\Modules\ExpressPickup\Services\ReceiptPrintService;
use App\Modules\ExpressPickup\Support\ReceiptTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCampusPrinterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CampusPrinter::query()->latest('id');
        if ($request->filled('campus_id')) {
            $query->where('campus_id', (int) $request->query('campus_id'));
        }

        return ApiResponse::success([
            'items' => $query->limit(100)->get()->map(fn (CampusPrinter $printer): array => $this->present($printer))->all(),
            'vendors' => collect(CampusPrinter::VENDORS)->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
                'ready' => $code === CampusPrinter::VENDOR_FEIE,
            ])->values()->all(),
            'variables' => collect(ReceiptTemplateRenderer::VARIABLES)->map(fn (string $label, string $key): array => [
                'key' => '${'.$key.'}',
                'label' => $label,
            ])->values()->all(),
            'default_template' => app(ReceiptPrintService::class)->defaultTemplate(),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $audit,
        ReceiptPrintService $receipts,
    ): JsonResponse {
        $data = $this->validatePrinter($request);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $campusId = $this->resolveCampusId($request, isset($data['campus_id']) ? (int) $data['campus_id'] : null);

        $printer = new CampusPrinter([
            'campus_id' => $campusId,
            'name' => $data['name'],
            'vendor' => $data['vendor'],
            'sn' => $data['sn'],
            'username' => $data['username'] ?? null,
            'paper_width' => (int) ($data['paper_width'] ?? 58),
            'template' => $data['template'] ?? $receipts->defaultTemplate(),
            'status' => $data['status'] ?? 'enabled',
            'created_by_admin_id' => $admin->id,
            'updated_by_admin_id' => $admin->id,
        ]);
        $printer->setSecret($data['secret'] ?? null);
        $printer->save();
        $presented = $this->present($printer);
        $audit->record($admin, 'express_pickup.printer.create', 'campus_printer', $printer->public_id, null, $presented, '新增校区小票机。');

        return ApiResponse::success($presented, 201, '小票机已创建。');
    }

    public function update(
        Request $request,
        CampusPrinter $printer,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $this->validatePrinter($request, true);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->present($printer);
        $printer->fill([
            'name' => $data['name'],
            'vendor' => $data['vendor'],
            'sn' => $data['sn'],
            'username' => $data['username'] ?? null,
            'paper_width' => (int) ($data['paper_width'] ?? $printer->paper_width),
            'template' => $data['template'] ?? $printer->template,
            'status' => $data['status'] ?? $printer->status,
            'updated_by_admin_id' => $admin->id,
        ]);
        if (! empty($data['secret'])) {
            $printer->setSecret((string) $data['secret']);
        } elseif (! empty($data['clear_secret'])) {
            $printer->setSecret(null);
        }
        $printer->save();
        $after = $this->present($printer);
        $audit->record($admin, 'express_pickup.printer.update', 'campus_printer', $printer->public_id, $before, $after, '编辑校区小票机。');

        return ApiResponse::success($after, 200, '小票机已保存。');
    }

    public function destroy(
        Request $request,
        CampusPrinter $printer,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->present($printer);
        $id = $printer->public_id;
        $printer->delete();
        $audit->record($admin, 'express_pickup.printer.delete', 'campus_printer', $id, $before, null, '删除校区小票机。');

        return ApiResponse::success(['id' => $id], 200, '小票机已删除。');
    }

    public function test(
        Request $request,
        CampusPrinter $printer,
        ReceiptPrintService $receipts,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $receipts->testPrinter($printer);
        $audit->record($admin, 'express_pickup.printer.test', 'campus_printer', $printer->public_id, null, $result, '测试小票机连接。');

        return ApiResponse::success([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'printer' => $this->present($printer->fresh()),
        ], $result['ok'] ? 200 : 422);
    }

    public function preview(
        Request $request,
        ReceiptPrintService $receipts,
    ): JsonResponse {
        $data = $request->validate([
            'template' => ['nullable', 'string', 'max:20000'],
            'paper_width' => ['nullable', 'integer', Rule::in([58, 80])],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
        ]);
        $campusId = $this->resolveCampusId($request, isset($data['campus_id']) ? (int) $data['campus_id'] : null, false);
        $printer = new CampusPrinter([
            'campus_id' => $campusId,
            'paper_width' => (int) ($data['paper_width'] ?? 58),
            'template' => $data['template'] ?? null,
        ]);

        return ApiResponse::success(['content' => $receipts->preview($printer)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePrinter(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'name' => [$required, 'string', 'max:60'],
            'vendor' => [$required, Rule::in(array_keys(CampusPrinter::VENDORS))],
            'sn' => [$required, 'string', 'max:64'],
            'username' => ['nullable', 'string', 'max:64'],
            'secret' => ['nullable', 'string', 'max:200'],
            'clear_secret' => ['nullable', 'boolean'],
            'paper_width' => ['nullable', 'integer', Rule::in([58, 80])],
            'template' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
        ]);
    }

    private function resolveCampusId(Request $request, ?int $input, bool $required = true): ?int
    {
        $campusId = $input ?? $request->attributes->get('campus_database_id');
        if ($required && $campusId === null) {
            throw ValidationException::withMessages(['campus_id' => ['请先选择校区。']]);
        }

        return $campusId === null ? null : (int) $campusId;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CampusPrinter $printer): array
    {
        return [
            'id' => $printer->public_id,
            'campus_id' => $printer->campus_id,
            'name' => $printer->name,
            'vendor' => $printer->vendor,
            'vendor_label' => CampusPrinter::VENDORS[$printer->vendor] ?? $printer->vendor,
            'sn' => $printer->sn,
            'username' => $printer->username,
            'secret_configured' => $printer->secret_encrypted !== null && $printer->secret_encrypted !== '',
            'paper_width' => $printer->paper_width,
            'template' => $printer->template,
            'status' => $printer->status,
            'last_test_at' => $printer->last_test_at?->toIso8601String(),
            'last_test_status' => $printer->last_test_status,
            'last_test_message' => $printer->last_test_message,
            'created_at' => $printer->created_at?->toIso8601String(),
            'updated_at' => $printer->updated_at?->toIso8601String(),
        ];
    }
}
