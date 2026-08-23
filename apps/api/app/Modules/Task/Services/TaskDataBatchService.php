<?php

namespace App\Modules\Task\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskDataBatch;
use App\Modules\Task\Models\TaskDataItem;
use App\Modules\Task\Models\TaskVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskDataBatchService
{
    /** @param array<string,string> $mapping */
    public function import(AdminUser $admin, UploadedFile $file, string $name, array $mapping): TaskDataBatch
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => ['当前仅支持 CSV 文件，请另存为 UTF-8 CSV 后上传。']]);
        }
        [$headers, $rows] = $this->readCsv($file->getRealPath());
        $mapping = $this->validateMapping($headers, $mapping);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['CSV 文件没有可导入的数据行。']]);
        }
        if (count($rows) > 100000) {
            throw ValidationException::withMessages(['file' => ['单个批次最多允许导入 100000 行。']]);
        }

        $prepared = [];
        foreach ($rows as $index => $source) {
            $mapped = [];
            $errors = [];
            foreach ($mapping as $target => $sourceHeader) {
                $value = trim((string) ($source[$sourceHeader] ?? ''));
                $mapped[$target] = $value;
                if ($value === '') {
                    $errors[] = "{$target} 不能为空";
                }
            }
            $prepared[] = ['row_no' => $index + 2, 'source' => $source, 'mapped' => $mapped, 'errors' => $errors];
        }

        return DB::transaction(function () use ($admin, $file, $name, $headers, $mapping, $prepared): TaskDataBatch {
            $snapshot = $this->snapshotHash($mapping, collect($prepared)->pluck('mapped')->all());
            $invalid = collect($prepared)->filter(fn (array $row) => $row['errors'] !== [])->count();
            $batch = TaskDataBatch::query()->create([
                'name' => $name,
                'original_filename' => $file->getClientOriginalName(),
                'source_format' => 'csv',
                'source_headers' => $headers,
                'field_mapping' => $mapping,
                'row_count' => count($prepared),
                'valid_count' => count($prepared) - $invalid,
                'invalid_count' => $invalid,
                'status' => $invalid === 0 ? 'ready' : 'preflight_failed',
                'snapshot_hash' => $snapshot,
                'created_by_admin_id' => $admin->id,
            ]);
            foreach (array_chunk($prepared, 500) as $chunk) {
                foreach ($chunk as $row) {
                    TaskDataItem::query()->create([
                        'task_data_batch_id' => $batch->id,
                        'row_no' => $row['row_no'],
                        'source_payload' => $row['source'],
                        'mapped_payload' => $row['mapped'],
                        'validation_errors' => $row['errors'] ?: null,
                        'status' => $row['errors'] === [] ? 'available' : 'invalid',
                    ]);
                }
            }

            return $batch->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function reference(string $publicId): array
    {
        $batch = TaskDataBatch::query()->where('public_id', $publicId)->first();
        if ($batch === null) {
            throw ValidationException::withMessages(['data_batch_id' => ['选择的数据批次不存在。']]);
        }
        if ($batch->status !== 'ready' || $batch->invalid_count > 0 || $batch->valid_count < 1 || $batch->locked_at !== null) {
            throw ValidationException::withMessages(['data_batch_id' => ['数据批次必须预检全部通过、尚未发布且至少包含一条有效数据。']]);
        }

        return [
            'id' => $batch->public_id,
            'name' => $batch->name,
            'snapshot_hash' => $batch->snapshot_hash,
            'field_mapping' => $batch->field_mapping,
            'item_count' => $batch->valid_count,
        ];
    }

    public function lockForPublish(Task $task, TaskVersion $version): ?TaskDataBatch
    {
        $reference = $version->content['data_batch'] ?? null;
        if (! is_array($reference)) {
            return null;
        }
        $batch = TaskDataBatch::query()->where('public_id', $reference['id'] ?? '')->lockForUpdate()->first();
        if ($batch === null || $batch->status !== 'ready' || $batch->locked_at !== null) {
            throw ValidationException::withMessages(['data_batch_id' => ['任务关联的数据批次不存在、已被占用或当前不可发布。']]);
        }
        if (! hash_equals((string) $batch->snapshot_hash, (string) ($reference['snapshot_hash'] ?? ''))) {
            throw ValidationException::withMessages(['data_batch_id' => ['数据批次在任务草稿创建后发生过变化，请重新保存任务版本。']]);
        }
        if ($batch->valid_count < (int) ($version->content['reward_quota_total'] ?? 0)) {
            throw ValidationException::withMessages(['data_batch_id' => ['有效数据条数少于任务奖励总名额。']]);
        }
        $batch->update([
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        return $batch->fresh();
    }

    public function allocateForClaim(TaskClaim $claim, TaskVersion $version): ?TaskDataItem
    {
        $reference = $version->content['data_batch'] ?? null;
        if (! is_array($reference)) {
            return null;
        }
        $batch = TaskDataBatch::query()->where('public_id', $reference['id'] ?? '')->lockForUpdate()->first();
        if ($batch === null || $batch->task_version_id !== $version->id || $batch->locked_at === null) {
            throw ValidationException::withMessages(['task' => ['任务数据批次快照无效，请联系平台管理员。']]);
        }
        $item = TaskDataItem::query()
            ->where('task_data_batch_id', $batch->id)
            ->where('status', 'available')
            ->orderBy('row_no')
            ->lockForUpdate()
            ->first();
        if ($item === null) {
            throw ValidationException::withMessages(['task' => ['该任务的可领取批次数据已用完。']]);
        }
        $item->update([
            'status' => 'allocated',
            'allocated_task_claim_id' => $claim->id,
            'allocated_user_id' => $claim->user_id,
            'allocated_at' => now(),
        ]);
        DB::table('task_data_allocations')->insert([
            'task_data_item_id' => $item->id,
            'task_claim_id' => $claim->id,
            'user_id' => $claim->user_id,
            'status' => 'allocated',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $item->fresh();
    }

    public function releaseForClaim(TaskClaim $claim): void
    {
        $item = TaskDataItem::query()->where('allocated_task_claim_id', $claim->id)->lockForUpdate()->first();
        if ($item === null || $item->status !== 'allocated') {
            return;
        }
        $item->update([
            'status' => 'available',
            'allocated_task_claim_id' => null,
            'allocated_user_id' => null,
            'allocated_at' => null,
            'release_count' => $item->release_count + 1,
            'last_released_at' => now(),
        ]);
        DB::table('task_data_allocations')->where('task_claim_id', $claim->id)->where('status', 'allocated')->update([
            'status' => 'released',
            'released_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function availableCount(TaskVersion $version): ?int
    {
        $id = $version->content['data_batch']['id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $batch = TaskDataBatch::query()->where('public_id', $id)->first();

        return $batch?->items()->where('status', 'available')->count() ?? 0;
    }

    /** @return array<string,mixed> */
    public function present(TaskDataBatch $batch, bool $includePreview = false): array
    {
        return [
            'id' => $batch->public_id,
            'name' => $batch->name,
            'original_filename' => $batch->original_filename,
            'source_format' => $batch->source_format,
            'source_headers' => $batch->source_headers,
            'field_mapping' => $batch->field_mapping,
            'row_count' => $batch->row_count,
            'valid_count' => $batch->valid_count,
            'invalid_count' => $batch->invalid_count,
            'available_count' => $batch->items()->where('status', 'available')->count(),
            'allocated_count' => $batch->items()->where('status', 'allocated')->count(),
            'status' => $batch->status,
            'snapshot_hash' => $batch->snapshot_hash,
            'locked_at' => $batch->locked_at?->toIso8601String(),
            'preview' => $includePreview ? $batch->items()->orderBy('row_no')->limit(20)->get()->map(fn (TaskDataItem $item) => [
                'row_no' => $item->row_no,
                'mapped_payload' => $item->mapped_payload,
                'validation_errors' => $item->validation_errors ?? [],
                'status' => $item->status,
            ])->values()->all() : null,
        ];
    }

    /** @return array{0:array<int,string>,1:array<int,array<string,string>>} */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['无法读取上传的 CSV 文件。']]);
        }
        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages(['file' => ['CSV 文件为空。']]);
            }
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
            $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
            $headers = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter);
            $headers = array_map(fn ($value) => trim((string) $value), $headers);
            if ($headers === [] || in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => ['CSV 表头不能为空且不能重复。']]);
            }
            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($values === [null] || collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                    continue;
                }
                $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
                $rows[] = array_combine($headers, array_map(fn ($value) => (string) $value, $values));
            }

            return [$headers, $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int,string> $headers @param array<string,string> $mapping @return array<string,string> */
    private function validateMapping(array $headers, array $mapping): array
    {
        if ($mapping === []) {
            throw ValidationException::withMessages(['field_mapping' => ['请至少配置一个输出字段映射。']]);
        }
        foreach ($mapping as $target => $source) {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string) $target)) {
                throw ValidationException::withMessages(['field_mapping' => ["输出字段 {$target} 格式无效，只能使用英文字母、数字和下划线且必须以字母开头。"]]);
            }
            if (! in_array($source, $headers, true)) {
                throw ValidationException::withMessages(['field_mapping' => ["来源字段 {$source} 不在 CSV 表头中。"]]);
            }
        }
        if (count($mapping) !== count(array_unique(array_values($mapping)))) {
            throw ValidationException::withMessages(['field_mapping' => ['同一个 CSV 字段不能重复映射。']]);
        }

        return $mapping;
    }

    /** @param array<string,string> $mapping @param array<int,array<string,string>> $rows */
    private function snapshotHash(array $mapping, array $rows): string
    {
        return hash('sha256', json_encode(['mapping' => $mapping, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
