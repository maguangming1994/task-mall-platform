<?php

namespace App\Modules\Task\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class TaskCycle
{
    /** @return array{key:string,starts_at:?CarbonImmutable,ends_at:?CarbonImmutable} */
    public static function resolve(string $taskType, ?CarbonImmutable $at = null): array
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone('Asia/Shanghai');

        return match ($taskType) {
            'one_time' => ['key' => 'once', 'starts_at' => null, 'ends_at' => null],
            'milestone' => ['key' => 'milestone', 'starts_at' => null, 'ends_at' => null],
            'daily' => ['key' => $now->format('Y-m-d'), 'starts_at' => $now->startOfDay(), 'ends_at' => $now->endOfDay()],
            'weekly' => ['key' => $now->format('o-\WW'), 'starts_at' => $now->startOfWeek(), 'ends_at' => $now->endOfWeek()],
            'monthly' => ['key' => $now->format('Y-m'), 'starts_at' => $now->startOfMonth(), 'ends_at' => $now->endOfMonth()],
            // Legacy repeatable tasks keep their existing daily reset semantics.
            'repeatable' => ['key' => 'repeat-'.$now->format('Y-m-d'), 'starts_at' => $now->startOfDay(), 'ends_at' => $now->endOfDay()],
            default => throw ValidationException::withMessages(['task_type' => ['任务周期类型无效。']]),
        };
    }

    public static function isPeriodic(string $taskType): bool
    {
        return in_array($taskType, ['daily', 'weekly', 'monthly', 'repeatable'], true);
    }
}
