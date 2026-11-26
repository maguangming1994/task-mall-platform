<?php

namespace App\Modules\ExpressPickup\Services;

use App\Models\User;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use Illuminate\Validation\ValidationException;

class ExpressPickupRunnerService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data): ExpressPickupRunner
    {
        if ($user->campus_id === null) {
            throw ValidationException::withMessages(['campus' => ['请先绑定学校后再申请成为跑腿。']]);
        }
        $existing = ExpressPickupRunner::query()
            ->where('campus_id', (int) $user->campus_id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null && $existing->status === 'active') {
            throw ValidationException::withMessages(['runner' => ['你已经是该学校的跑腿员。']]);
        }
        if ($existing !== null && $existing->status === 'pending') {
            throw ValidationException::withMessages(['runner' => ['申请正在审核中，请耐心等待。']]);
        }

        $attributes = [
            'real_name' => (string) $data['real_name'],
            'phone' => (string) $data['phone'],
            'status' => 'pending',
            'applied_at' => now(),
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
            'reject_reason' => null,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing->fresh();
        }

        return ExpressPickupRunner::query()->create($attributes + [
            'campus_id' => (int) $user->campus_id,
            'user_id' => $user->id,
        ]);
    }

    public function review(int $adminId, ExpressPickupRunner $runner, string $status, ?string $reason = null): ExpressPickupRunner
    {
        if (! in_array($status, ['active', 'rejected', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => ['审核状态不合法。']]);
        }

        return tap($runner, function (ExpressPickupRunner $model) use ($adminId, $status, $reason): void {
            $model->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by_admin_id' => $adminId,
                'reject_reason' => $status === 'rejected' ? $reason : null,
            ])->save();
        })->fresh();
    }

    public function isActiveRunner(User $user, int $campusId): bool
    {
        return ExpressPickupRunner::query()
            ->where('campus_id', $campusId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ExpressPickupRunner $runner): array
    {
        return [
            'id' => $runner->public_id,
            'campus_id' => $runner->campus_id,
            'status' => $runner->status,
            'real_name' => $runner->real_name,
            'phone' => $runner->phone,
            'applied_at' => $runner->applied_at?->toIso8601String(),
            'reviewed_at' => $runner->reviewed_at?->toIso8601String(),
            'reject_reason' => $runner->reject_reason,
            'user' => $runner->user === null ? null : [
                'id' => $runner->user->public_id,
                'display_name' => $runner->user->nickname,
            ],
            'created_at' => $runner->created_at?->toIso8601String(),
        ];
    }
}
