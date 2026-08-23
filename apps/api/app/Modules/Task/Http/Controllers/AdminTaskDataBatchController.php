<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Task\Models\TaskDataBatch;
use App\Modules\Task\Services\TaskDataBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminTaskDataBatchController extends Controller
{
    public function index(TaskDataBatchService $batches): JsonResponse
    {
        $items = TaskDataBatch::query()->latest('id')->get()->map(fn (TaskDataBatch $batch) => $batches->present($batch))->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function show(TaskDataBatch $taskDataBatch, TaskDataBatchService $batches): JsonResponse
    {
        return ApiResponse::success($batches->present($taskDataBatch, true));
    }

    public function store(Request $request, TaskDataBatchService $batches): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'file' => ['required', 'file', 'max:20480'],
            'field_mapping' => ['required'],
        ]);
        $mapping = is_string($data['field_mapping']) ? json_decode($data['field_mapping'], true) : $data['field_mapping'];
        if (! is_array($mapping) || ! collect($mapping)->every(fn ($value, $key) => is_string($key) && is_string($value))) {
            return ApiResponse::error('VALIDATION_FAILED', '字段映射格式无效。', 422, ['field_mapping' => ['字段映射必须是“输出字段名: CSV 表头”的 JSON 对象。']]);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $batch = $batches->import($admin, $request->file('file'), $data['name'], $mapping);

        return ApiResponse::success($batches->present($batch, true), 201);
    }

    public function export(TaskDataBatch $taskDataBatch): StreamedResponse
    {
        $headers = array_keys($taskDataBatch->field_mapping);
        $filename = 'task-data-'.$taskDataBatch->public_id.'.csv';

        return response()->streamDownload(function () use ($taskDataBatch, $headers): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['row_no', ...$headers, 'allocation_status', 'claim_id', 'user_id', 'claim_status', 'release_count', 'allocation_history_count']);
            $historyCounts = DB::table('task_data_allocations')
                ->whereIn('task_data_item_id', $taskDataBatch->items()->select('id'))
                ->selectRaw('task_data_item_id, count(*) as aggregate')
                ->groupBy('task_data_item_id')
                ->pluck('aggregate', 'task_data_item_id');
            $taskDataBatch->items()->with(['claim', 'user'])->orderBy('row_no')->chunk(500, function ($items) use ($output, $headers, $historyCounts): void {
                foreach ($items as $item) {
                    $values = collect($headers)->map(fn (string $key) => $this->safeCsv((string) ($item->mapped_payload[$key] ?? '')))->all();
                    fputcsv($output, [
                        $item->row_no,
                        ...$values,
                        $item->status,
                        $item->claim?->public_id,
                        $item->user?->public_id,
                        $item->claim?->status,
                        $item->release_count,
                        (int) ($historyCounts[$item->id] ?? 0),
                    ]);
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
