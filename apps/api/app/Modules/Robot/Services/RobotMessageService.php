<?php

namespace App\Modules\Robot\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Robot\Exceptions\XpRobotException;
use App\Modules\Robot\Models\RobotMessageRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RobotMessageService
{
    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_DISPATCHING = 'DISPATCHING';

    public const STUCK_DISPATCHING_MINUTES = 5;

    public function __construct(
        private readonly XpRobotClient $client,
        private readonly XpRobotSettingsService $settings,
    ) {}

    /**
     * 统一发送入口：落库后立即调用 XP 派发。
     *
     * @param  array{device_id:string,wxid:string,group_id:string,group_name?:string|null,msg_type:string,message?:string|null,payload?:array<string,mixed>|null}  $data
     */
    public function send(AdminUser $admin, array $data, string $idempotencyKey, array $context = []): RobotMessageRequest
    {
        $this->settings->apiKey();
        $record = $this->ensureRecord($admin, $data, $idempotencyKey, 'SUBMITTING', $context);
        if (! $record->wasRecentlyCreated) {
            return $record;
        }

        return $this->dispatch($record);
    }

    /** 向后兼容：等同于 send()，强制 msg_type=text。 */
    public function sendGroupText(AdminUser $admin, array $data, string $idempotencyKey, array $context = []): RobotMessageRequest
    {
        $data['msg_type'] = 'text';

        return $this->send($admin, $data, $idempotencyKey, $context);
    }

    /**
     * 创建定时发送计划：仅落库，不调用 XP，到点由调度器派发。
     *
     * @param  array{device_id:string,wxid:string,group_id:string,group_name?:string|null,msg_type:string,message?:string|null,payload?:array<string,mixed>|null,scheduled_at:\DateTimeInterface|string}  $data
     */
    public function schedule(AdminUser $admin, array $data, string $idempotencyKey): RobotMessageRequest
    {
        return $this->ensureRecord($admin, $data, $idempotencyKey, self::STATUS_SCHEDULED);
    }

    /** 创建或重放一个组合，立即组合在事务外按顺序派发。 */
    public function sendBatch(AdminUser $admin, array $data, string $idempotencyKey): Collection
    {
        $this->settings->apiKey();
        $batchId = $this->batchId($admin, $idempotencyKey);
        $scheduled = filled($data['scheduled_at'] ?? null);

        try {
            $records = DB::transaction(function () use ($admin, $data, $idempotencyKey, $batchId, $scheduled): Collection {
                $existing = $this->batchRecords($batchId, true);
                if ($existing->isNotEmpty()) {
                    return $existing;
                }

                return collect(array_values($data['items']))->map(function (array $item, int $index) use (
                    $admin,
                    $data,
                    $idempotencyKey,
                    $batchId,
                    $scheduled,
                ): RobotMessageRequest {
                    return RobotMessageRequest::query()->create([
                        'created_by_admin_id' => $admin->id,
                        'trigger_type' => 'MANUAL',
                        'idempotency_key_hash' => hash('sha256', $idempotencyKey.':batch:'.($index + 1)),
                        'batch_id' => $batchId,
                        'batch_sequence' => $index + 1,
                        'device_id' => $data['device_id'],
                        'wxid' => $data['wxid'],
                        'group_id' => $data['group_id'],
                        'group_name' => $this->nullableString($data['group_name'] ?? null),
                        'msg_type' => $item['msg_type'],
                        'message' => $item['message'] ?? null,
                        'payload' => $item['payload'] ?? null,
                        'status' => $scheduled ? self::STATUS_SCHEDULED : self::STATUS_DISPATCHING,
                        'queued' => false,
                        'scheduled_at' => $this->normalizeScheduledAt($data['scheduled_at'] ?? null),
                    ]);
                });
            });
        } catch (QueryException $exception) {
            $records = $this->batchRecords($batchId);
            if ($records->isEmpty()) {
                throw $exception;
            }
        }

        $this->assertBatchMatches($records, $admin, $data, $batchId);
        $created = $records->every(fn (RobotMessageRequest $record): bool => $record->wasRecentlyCreated);
        if (! $scheduled && $created) {
            return $this->dispatchBatch($records);
        }

        return $this->batchRecords($batchId);
    }

    public function batchId(AdminUser $admin, string $idempotencyKey): string
    {
        return hash('sha256', 'robot-message-batch:'.$admin->id.':'.$idempotencyKey);
    }

    /** 取消尚未派发的定时计划。 */
    public function cancel(RobotMessageRequest $record): RobotMessageRequest
    {
        if (filled($record->batch_id)) {
            throw ValidationException::withMessages([
                'batch_id' => '组合消息必须整组取消，不能单独取消其中一条。',
            ]);
        }
        if ($record->status !== self::STATUS_SCHEDULED) {
            throw ValidationException::withMessages([
                'status' => '只有待发送（SCHEDULED）的定时消息可以取消。',
            ]);
        }
        $record->forceFill([
            'status' => 'CANCELED',
            'canceled_at' => now(),
        ])->save();

        return $record->fresh();
    }

    /** 整组取消未到点的定时组合。 */
    public function cancelBatch(string $batchId): Collection
    {
        return DB::transaction(function () use ($batchId): Collection {
            $records = $this->batchRecords($batchId, true);
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['batch_id' => '组合消息不存在。']);
            }
            $now = now();
            $cancelable = $records->every(fn (RobotMessageRequest $record): bool => $record->status === self::STATUS_SCHEDULED
                && $record->scheduled_at !== null
                && $record->scheduled_at->gt($now));
            if (! $cancelable) {
                throw ValidationException::withMessages([
                    'status' => '只有全部子消息仍为待发送且未到点的定时组合可以取消。',
                ]);
            }

            $records->each(fn (RobotMessageRequest $record) => $record->forceFill([
                'status' => 'CANCELED',
                'canceled_at' => $now,
            ])->save());

            return $this->batchRecords($batchId);
        });
    }

    /** 按“旧单条或完整组合”为工作单元派发到期的定时消息。 */
    public function dispatchDue(int $limit = 50): int
    {
        $this->settings->apiKey();
        $stuckBefore = now()->subMinutes(self::STUCK_DISPATCHING_MINUTES);

        RobotMessageRequest::query()
            ->whereNull('batch_id')
            ->where('status', self::STATUS_DISPATCHING)
            ->where('updated_at', '<', $stuckBefore)
            ->update(['status' => self::STATUS_SCHEDULED]);
        $this->recoverStuckBatches($stuckBefore);

        $units = DB::transaction(function () use ($limit): array {
            $now = now();
            $candidates = RobotMessageRequest::query()
                ->where('status', self::STATUS_SCHEDULED)
                ->where('scheduled_at', '<=', $now)
                ->orderBy('id')
                ->get(['id', 'batch_id']);
            $seenBatches = [];
            $units = [];

            foreach ($candidates as $candidate) {
                if (count($units) >= $limit) {
                    break;
                }

                if (filled($candidate->batch_id)) {
                    $batchId = $candidate->batch_id;
                    if (isset($seenBatches[$batchId])) {
                        continue;
                    }
                    $seenBatches[$batchId] = true;
                    $records = $this->batchRecords($batchId, true);
                    $ready = $records->isNotEmpty() && $records->every(
                        fn (RobotMessageRequest $record): bool => $record->status === self::STATUS_SCHEDULED
                            && $record->scheduled_at !== null
                            && $record->scheduled_at->lte($now),
                    );
                    if (! $ready) {
                        continue;
                    }
                } else {
                    $record = RobotMessageRequest::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if ($record === null || $record->status !== self::STATUS_SCHEDULED
                        || $record->scheduled_at === null || $record->scheduled_at->gt($now)) {
                        continue;
                    }
                    $records = collect([$record]);
                }

                $records->each(fn (RobotMessageRequest $record) => $record->forceFill([
                    'status' => self::STATUS_DISPATCHING,
                ])->save());
                $units[] = $records;
            }

            return $units;
        });

        foreach ($units as $records) {
            if (filled($records->first()->batch_id)) {
                $this->dispatchBatch($records);
            } else {
                $this->dispatch($records->first()->fresh());
            }
        }

        return count($units);
    }

    /** 顺序提交组合；当前条无法安全确认时，后续不再提交 XP。 */
    public function dispatchBatch(Collection $records): Collection
    {
        $records = $records->sortBy('batch_sequence')->values();
        foreach ($records as $record) {
            $current = $this->dispatch($record->fresh());
            $failed = in_array($current->status, [
                'SUBMIT_FAILED', 'SUBMIT_UNKNOWN', 'FAILED', 'FAIL', 'ERROR', 'CANCELED', 'SKIPPED',
            ], true);
            if ($failed || blank($current->cmd_id)) {
                $this->skipBatchAfter($current->batch_id, (int) $current->batch_sequence);
                break;
            }
        }

        return $this->batchRecords((string) $records->first()->batch_id);
    }

    private function recoverStuckBatches(\DateTimeInterface $stuckBefore): void
    {
        $batchIds = RobotMessageRequest::query()
            ->whereNotNull('batch_id')
            ->where('status', self::STATUS_DISPATCHING)
            ->where('updated_at', '<', $stuckBefore)
            ->distinct()
            ->pluck('batch_id');

        foreach ($batchIds as $batchId) {
            DB::transaction(function () use ($batchId, $stuckBefore): void {
                $records = $this->batchRecords($batchId, true);
                $current = $records->first(fn (RobotMessageRequest $record): bool => $record->status === self::STATUS_DISPATCHING
                    && $record->updated_at !== null
                    && $record->updated_at->lt($stuckBefore));
                if ($current === null) {
                    return;
                }

                $current->forceFill([
                    'status' => 'SUBMIT_UNKNOWN',
                    'error_code' => 'BATCH_DISPATCH_INTERRUPTED',
                    'error_message' => '组合派发中断，无法确认该消息是否已提交 XP，请勿直接重发。',
                ])->save();
                $this->skipBatchAfter($batchId, (int) $current->batch_sequence);
            });
        }
    }

    /** 调用 XP 实际发送一条记录，并回写 cmd_id / 状态。 */
    public function dispatch(RobotMessageRequest $record): RobotMessageRequest
    {
        try {
            $result = match ($record->msg_type) {
                'image' => $this->client->sendGroupImage($record->device_id, $record->wxid, $record->group_id, (string) $record->message),
                'link' => $this->client->sendGroupLinkCard($record->device_id, $record->wxid, $record->group_id, $record->payload ?? []),
                default => $this->client->sendGroupText($record->device_id, $record->wxid, $record->group_id, (string) $record->message),
            };
        } catch (XpRobotException $exception) {
            $record->forceFill([
                'status' => $exception->indeterminate() ? 'SUBMIT_UNKNOWN' : 'SUBMIT_FAILED',
                'error_code' => $exception->indeterminate() ? 'XP_SUBMIT_UNKNOWN' : 'XP_SUBMIT_FAILED',
                'error_message' => $exception->getMessage(),
                'submitted_at' => now(),
                'dispatched_at' => now(),
            ])->save();

            return $record->fresh();
        }

        $cmdId = trim((string) ($result['cmd_id'] ?? ''));
        $providerStatus = strtoupper(trim((string) ($result['status'] ?? '')));
        $record->forceFill([
            'cmd_id' => $cmdId !== '' ? $cmdId : null,
            'resolved_device_id' => $this->nullableString($result['resolved_device_id'] ?? null),
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'status' => $providerStatus !== '' ? $providerStatus : 'SUBMIT_UNKNOWN',
            'queued' => (bool) ($result['queued'] ?? $providerStatus === 'QUEUED'),
            'error_code' => $cmdId === '' ? 'XP_RESPONSE_INCOMPLETE' : ($providerStatus === '' ? 'XP_STATUS_MISSING' : null),
            'error_message' => $cmdId === ''
                ? 'XP 未返回 cmd_id，无法自动确认投递结果，请勿直接重发。'
                : ($providerStatus === '' ? 'XP 未返回消息状态，请稍后刷新。' : null),
            'submitted_at' => now(),
            'dispatched_at' => now(),
        ])->save();

        return $record->fresh();
    }

    /**
     * 幂等落库：相同 idempotency_key 复用已有记录，不重复创建。
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureRecord(AdminUser $admin, array $data, string $idempotencyKey, string $status, array $context = []): RobotMessageRequest
    {
        $hash = hash('sha256', $idempotencyKey);
        $existing = $this->findByIdempotencyKey($admin, $hash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return RobotMessageRequest::query()->create([
                'created_by_admin_id' => $admin->id,
                'forward_config_id' => $context['forward_config_id'] ?? null,
                'trigger_type' => $context['trigger_type'] ?? 'MANUAL',
                'idempotency_key_hash' => $hash,
                'batch_id' => $data['batch_id'] ?? null,
                'batch_sequence' => $data['batch_sequence'] ?? null,
                'device_id' => $data['device_id'],
                'wxid' => $data['wxid'],
                'group_id' => $data['group_id'],
                'group_name' => $this->nullableString($data['group_name'] ?? null),
                'msg_type' => $data['msg_type'] ?? 'text',
                'message' => $data['message'] ?? null,
                'payload' => $data['payload'] ?? null,
                'status' => $status,
                'queued' => false,
                'scheduled_at' => $this->normalizeScheduledAt($data['scheduled_at'] ?? null),
            ]);
        } catch (QueryException $exception) {
            $existing = $this->findByIdempotencyKey($admin, $hash);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function refresh(RobotMessageRequest $record): RobotMessageRequest
    {
        if (! filled($record->cmd_id)) {
            throw ValidationException::withMessages([
                'cmd_id' => '该记录没有 cmd_id，无法查询 XP 投递状态。',
            ]);
        }
        $this->settings->apiKey();

        try {
            $result = $this->client->messageStatus($record->cmd_id);
        } catch (XpRobotException $exception) {
            $record->forceFill([
                'error_code' => 'XP_STATUS_SYNC_FAILED',
                'error_message' => $exception->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            return $record->fresh();
        }

        $providerStatus = strtoupper(trim((string) ($result['status'] ?? '')));
        $record->forceFill([
            'resolved_device_id' => $this->nullableString($result['resolved_device_id'] ?? $record->resolved_device_id),
            'provider_status' => $providerStatus !== '' ? $providerStatus : $record->provider_status,
            'status' => $providerStatus !== ''
                ? $providerStatus
                : ($record->status === 'SUBMITTING' ? 'SUBMIT_UNKNOWN' : $record->status),
            'queued' => array_key_exists('queued', $result)
                ? (bool) $result['queued']
                : in_array($providerStatus !== '' ? $providerStatus : $record->status, ['QUEUED', 'PROCESSING'], true),
            'error_code' => $providerStatus === '' ? 'XP_STATUS_MISSING' : null,
            'error_message' => $providerStatus === '' ? 'XP 未返回消息状态，请稍后再次刷新。' : null,
            'last_synced_at' => now(),
        ])->save();

        return $record->fresh();
    }

    /** @return array<string, mixed> */
    public function present(RobotMessageRequest $record): array
    {
        return [
            'id' => $record->public_id,
            'batch_id' => $record->batch_id,
            'batch_sequence' => $record->batch_sequence,
            'forward_config_id' => $record->forwardConfig?->public_id,
            'trigger_type' => $record->trigger_type ?? 'MANUAL',
            'device_id' => $record->device_id,
            'resolved_device_id' => $record->resolved_device_id,
            'wxid' => $record->wxid,
            'group_id' => $record->group_id,
            'group_name' => $record->group_name,
            'message' => $record->message,
            'msg_type' => $record->msg_type,
            'payload' => $record->payload,
            'cmd_id' => $record->cmd_id,
            'status' => $record->status,
            'provider_status' => $record->provider_status,
            'queued' => $record->queued,
            'error_code' => $record->error_code,
            'error_message' => $record->error_message,
            'submitted_at' => $record->submitted_at?->toIso8601String(),
            'last_synced_at' => $record->last_synced_at?->toIso8601String(),
            'scheduled_at' => $record->scheduled_at?->toIso8601String(),
            'dispatched_at' => $record->dispatched_at?->toIso8601String(),
            'canceled_at' => $record->canceled_at?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentBatch(Collection $records): array
    {
        $records = $records->sortBy('batch_sequence')->values();

        return [
            'batch_id' => $records->first()?->batch_id,
            'batch_size' => $records->count(),
            'items' => $records->map(fn (RobotMessageRequest $record): array => $this->present($record))->values()->all(),
        ];
    }

    private function batchRecords(string $batchId, bool $lock = false): Collection
    {
        $query = RobotMessageRequest::query()->where('batch_id', $batchId)->orderBy('batch_sequence');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function assertBatchMatches(Collection $records, AdminUser $admin, array $data, string $batchId): void
    {
        $items = array_values($data['items']);
        $expectedScheduledAt = filled($data['scheduled_at'] ?? null)
            ? CarbonImmutable::parse($data['scheduled_at'])->getTimestamp()
            : null;
        $matches = $records->count() === count($items);

        foreach ($items as $index => $item) {
            $record = $records->get($index);
            if (! $record instanceof RobotMessageRequest) {
                $matches = false;

                continue;
            }
            $matches = $matches
                && $record->created_by_admin_id === $admin->id
                && $record->batch_id === $batchId
                && $record->batch_sequence === $index + 1
                && $record->device_id === $data['device_id']
                && $record->wxid === $data['wxid']
                && $record->group_id === $data['group_id']
                && $record->group_name === $this->nullableString($data['group_name'] ?? null)
                && $record->msg_type === $item['msg_type']
                && $record->message === ($item['message'] ?? null)
                && $record->payload == ($item['payload'] ?? null)
                && $record->scheduled_at?->getTimestamp() === $expectedScheduledAt;
        }

        if (! $matches) {
            throw ValidationException::withMessages([
                'idempotency_key' => '该 Idempotency-Key 已用于不同的组合内容，请更换后重试。',
            ]);
        }
    }

    private function skipBatchAfter(string $batchId, int $sequence): void
    {
        RobotMessageRequest::query()
            ->where('batch_id', $batchId)
            ->where('batch_sequence', '>', $sequence)
            ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_DISPATCHING])
            ->whereNull('submitted_at')
            ->whereNull('dispatched_at')
            ->orderBy('batch_sequence')
            ->get()
            ->each(fn (RobotMessageRequest $record) => $record->forceFill([
                'status' => 'SKIPPED',
                'error_code' => 'BATCH_PREVIOUS_FAILED',
                'error_message' => '组合中前一条消息发送失败或结果未知，本消息未提交 XP。',
            ])->save());
    }

    /** 数据库按应用时区保存 naive datetime；带时区的输入先转换，避免重复偏移。 */
    private function normalizeScheduledAt(\DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'Asia/Shanghai');

        return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
    }

    private function findByIdempotencyKey(AdminUser $admin, string $hash): ?RobotMessageRequest
    {
        return RobotMessageRequest::query()
            ->where('created_by_admin_id', $admin->id)
            ->where('idempotency_key_hash', $hash)
            ->first();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
