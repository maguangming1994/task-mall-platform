<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Notification\Models\NotificationBusinessEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NotificationBusinessEventService
{
    public function __construct(
        private readonly NotificationSceneRegistry $registry,
        private readonly BusinessNotificationService $notifications,
    ) {}

    /** @param array<string,mixed> $variables
     * @param  array<string,mixed>  $data
     */
    public function record(User $user, string $sceneCode, array $variables, string $eventKey, array $data = []): NotificationBusinessEvent
    {
        $definition = $this->registry->get($sceneCode);
        $normalized = [];
        foreach ($definition['allowed_variables'] as $name) {
            $value = $variables[$name] ?? '';
            $normalized[$name] = is_scalar($value) || $value === null ? (string) $value : '';
        }
        $hash = hash('sha256', $user->public_id.':'.$eventKey);
        $inserted = DB::table('notification_business_events')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'scene_code' => $sceneCode,
            'event_key_hash' => $hash,
            'variables' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = NotificationBusinessEvent::query()->where('event_key_hash', $hash)->firstOrFail();
        if ($inserted > 0) {
            DB::afterCommit(function () use ($event): void {
                try {
                    $this->process($event->id);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $event;
    }

    public function processPending(int $limit = 100): int
    {
        NotificationBusinessEvent::query()->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(5))->update(['status' => 'failed', 'next_retry_at' => now(), 'updated_at' => now()]);
        $count = 0;
        NotificationBusinessEvent::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempt_count', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                $count += $this->process($id) ? 1 : 0;
            });

        return $count;
    }

    public function process(int $id): bool
    {
        $event = DB::transaction(function () use ($id): ?NotificationBusinessEvent {
            $locked = NotificationBusinessEvent::query()->lockForUpdate()->find($id);
            if ($locked === null || $locked->attempt_count >= 5
                || ! in_array($locked->status, ['pending', 'failed'], true)
                || ($locked->next_retry_at !== null && $locked->next_retry_at->isFuture())) {
                return null;
            }
            $locked->update(['status' => 'processing', 'attempt_count' => $locked->attempt_count + 1, 'next_retry_at' => null]);

            return $locked->fresh();
        });
        if ($event === null) {
            return false;
        }

        try {
            $this->notifications->dispatch($event);
            $event->update(['status' => 'processed', 'processed_at' => now(), 'last_error_code' => null, 'last_error_message' => null]);

            return true;
        } catch (Throwable $exception) {
            $attempt = $event->attempt_count;
            $event->update([
                'status' => 'failed',
                'next_retry_at' => $attempt >= 5 ? null : now()->addMinutes(2 ** min($attempt, 5)),
                'last_error_code' => class_basename($exception),
                'last_error_message' => Str::limit($exception->getMessage(), 500),
            ]);
            report($exception);

            return false;
        }
    }
}
