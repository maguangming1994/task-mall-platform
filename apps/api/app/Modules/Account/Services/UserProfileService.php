<?php

namespace App\Modules\Account\Services;

use App\Models\User;
use App\Modules\Account\Models\UserMilestone;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserProfileService
{
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (array_key_exists('avatar_file_id', $data)) {
                $locked->avatar_file_id = $this->resolveAvatarId($locked, $data['avatar_file_id']);
            }
            if (array_key_exists('nickname', $data)) {
                $locked->nickname = $this->resolveNickname($data['nickname']);
            }
            if ($locked->profile_completed_at === null && $locked->nickname !== '' && $locked->avatar_file_id !== null && $locked->phone_verified_at !== null) {
                $locked->profile_completed_at = now();
                UserMilestone::query()->firstOrCreate(
                    ['user_id' => $locked->id, 'milestone_code' => 'profile_completed'],
                    ['reward_status' => 'pending', 'achieved_at' => now()],
                );
            }
            $locked->save();

            return $locked->fresh();
        });
    }

    private function resolveNickname(mixed $value): string
    {
        $nickname = is_string($value) ? Str::trim($value) : '';
        if ($nickname === '' || Str::length($nickname) > 80) {
            throw ValidationException::withMessages(['nickname' => ['昵称必须为 1 至 80 个字符。']]);
        }

        return $nickname;
    }

    private function resolveAvatarId(User $user, ?string $publicId): ?int
    {
        if ($publicId === null) {
            return null;
        }
        $file = UploadedFile::query()->where('public_id', $publicId)->first();
        if ($file === null || $file->owner_user_id !== $user->id || $file->purpose !== 'avatar' || $file->status !== 'clean') {
            throw ValidationException::withMessages(['avatar_file_id' => ['头像必须是本人已通过安全扫描的头像文件。']]);
        }

        return $file->id;
    }
}
