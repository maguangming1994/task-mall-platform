<?php

namespace App\Modules\Account\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAddressService
{
    public function create(User $user, array $data, string $idempotencyKey): UserAddress
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey): UserAddress {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $hash = $this->fingerprint($idempotencyKey);
            $existing = UserAddress::withTrashed()->where('user_id', $user->id)->where('idempotency_key_hash', $hash)->first();
            if ($existing !== null) {
                return $existing;
            }
            if (UserAddress::query()->where('user_id', $user->id)->count() >= 20) {
                throw ValidationException::withMessages(['address' => ['每个账号最多保存 20 个收货地址。']]);
            }
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! UserAddress::query()->where('user_id', $user->id)->exists();
            if ($makeDefault) {
                UserAddress::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return UserAddress::query()->create([...$data, 'user_id' => $user->id, 'is_default' => $makeDefault, 'idempotency_key_hash' => $hash]);
        });
    }

    public function update(User $user, UserAddress $address, array $data): UserAddress
    {
        $this->authorize($user, $address);

        return DB::transaction(function () use ($user, $address, $data): UserAddress {
            User::query()->lockForUpdate()->findOrFail($user->id);
            if (($data['is_default'] ?? false) === true) {
                UserAddress::query()->where('user_id', $user->id)->whereKeyNot($address->id)->update(['is_default' => false]);
            }
            if (($data['is_default'] ?? null) === false && $address->is_default) {
                unset($data['is_default']);
            }
            $address->update($data);

            return $address->fresh();
        });
    }

    public function delete(User $user, UserAddress $address): void
    {
        $this->authorize($user, $address);
        DB::transaction(function () use ($user, $address): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                UserAddress::query()->where('user_id', $user->id)->latest('updated_at')->first()?->update(['is_default' => true]);
            }
        });
    }

    public function authorize(User $user, UserAddress $address): void
    {
        if ($address->user_id !== $user->id) {
            abort(404);
        }
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('auth_tokens.identity_hash_secret')));
    }
}
