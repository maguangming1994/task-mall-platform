<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreApplicationService
{
    private const MAX_PENDING_STORES = 3;

    public function apply(User $user, array $data): Store
    {
        return DB::transaction(function () use ($user, $data): Store {
            $pending = Store::query()
                ->where('owner_user_id', $user->id)
                ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_ACTIVE, Store::STATUS_SUSPENDED])
                ->count();
            if ($pending >= self::MAX_PENDING_STORES) {
                throw ValidationException::withMessages(['store' => ['同一账号最多同时运营 3 家门店，请先关停不再使用的门店。']]);
            }
            $store = Store::query()->create([
                'owner_user_id' => $user->id,
                'campus_id' => $user->campus_id,
                'name' => trim((string) $data['name']),
                'logo_url' => $data['logo_url'] ?? null,
                'description' => $data['description'] ?? null,
                'contact_phone' => trim((string) $data['contact_phone']),
                'address' => $data['address'] ?? null,
                'business_hours' => $data['business_hours'] ?? null,
                'status' => Store::STATUS_PENDING,
                'applied_at' => now(),
            ]);
            StoreMember::query()->create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'role' => StoreMember::ROLE_OWNER,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $store->fresh('members');
        });
    }

    public function update(Store $store, User $user, array $data): Store
    {
        if ($store->owner_user_id !== $user->id) {
            throw ValidationException::withMessages(['store' => ['只有店主可以修改门店资料。']]);
        }

        $payload = collect($data)->only(['name', 'logo_url', 'description', 'contact_phone', 'address', 'business_hours'])->filter(fn ($value) => $value !== null)->all();
        if (isset($payload['name'])) {
            $payload['name'] = trim((string) $payload['name']);
        }
        if (isset($payload['contact_phone'])) {
            $payload['contact_phone'] = trim((string) $payload['contact_phone']);
        }
        $store->update($payload);

        return $store->fresh();
    }

    public function resubmit(Store $store, User $user): Store
    {
        if ($store->owner_user_id !== $user->id) {
            throw ValidationException::withMessages(['store' => ['只有店主可以重新提交开店申请。']]);
        }
        if ($store->status !== Store::STATUS_REJECTED) {
            throw ValidationException::withMessages(['store' => ['只有已驳回的门店可以重新提交申请。']]);
        }
        $store->update([
            'status' => Store::STATUS_PENDING,
            'reject_reason' => null,
            'applied_at' => now(),
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
        ]);

        return $store->fresh();
    }

    /** @return array<int, array<string, mixed>> */
    public function presentMine(User $user): array
    {
        $members = StoreMember::query()
            ->with('store')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        return $members->filter(fn (StoreMember $member) => $member->store !== null)->map(function (StoreMember $member) use ($user): array {
            $store = $member->store;

            return [
                'id' => $store->public_id,
                'name' => $store->name,
                'logo_url' => $store->logo_url,
                'description' => $store->description,
                'status' => $store->status,
                'role' => $member->role,
                'role_label' => match ($member->role) {
                    StoreMember::ROLE_OWNER => '店主',
                    StoreMember::ROLE_MANAGER => '店长',
                    default => '店员',
                },
                'is_owner' => $store->owner_user_id === $user->id,
                'reject_reason' => $store->reject_reason,
                'contact_phone' => $store->contact_phone,
                'address' => $store->address,
                'business_hours' => $store->business_hours,
                'applied_at' => $store->applied_at?->toIso8601String(),
                'reviewed_at' => $store->reviewed_at?->toIso8601String(),
                'created_at' => $store->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
