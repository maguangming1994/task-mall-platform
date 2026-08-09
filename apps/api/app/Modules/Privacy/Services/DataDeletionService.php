<?php

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Privacy\Models\DataDeletionJob;
use App\Modules\Privacy\Models\DataDeletionManifest;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DataDeletionService
{
    /** @return array<string,int> */
    public function executeCancellation(DataDeletionJob $job): array
    {
        $user = User::query()->lockForUpdate()->findOrFail($job->user_id);
        $stats = ['identities_anonymized' => 0, 'sessions_revoked' => 0, 'devices_revoked' => 0, 'addresses_deleted' => 0, 'objects_deleted' => 0];

        DB::transaction(function () use ($user, &$stats): void {
            $stats['sessions_revoked'] = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'account_cancelled', 'updated_at' => now()]);
            $stats['devices_revoked'] = UserDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $stats['identities_anonymized'] = UserIdentity::query()->where('user_id', $user->id)->delete();
            $stats['addresses_deleted'] = UserAddress::withTrashed()->where('user_id', $user->id)->forceDelete();
            UserAssetAccount::query()->where('user_id', $user->id)->update([
                'points_available' => 0, 'points_frozen' => 0, 'points_debt' => 0,
                'wallet_available_cents' => 0, 'wallet_frozen_cents' => 0, 'coupon_available' => 0,
            ]);
            $user->update([
                'nickname' => '已注销用户', 'avatar_file_id' => null, 'phone' => null, 'phone_hash' => null,
                'phone_verified_at' => null, 'profile_completed_at' => null, 'status' => 'cancelled', 'cancelled_at' => now(),
            ]);
            DataDeletionManifest::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['user_public_id_hash' => hash('sha256', $user->public_id), 'deletion_version' => 1, 'scopes' => ['profile', 'identities', 'devices', 'addresses', 'private_uploads'], 'effective_at' => now()],
            );
        });

        UploadedFile::query()->where('owner_user_id', $user->id)->whereNotIn('purpose', ['privacy_export'])->chunkById(100, function ($files) use (&$stats): void {
            foreach ($files as $file) {
                if (Storage::disk($file->disk)->exists($file->quarantine_path)) {
                    Storage::disk($file->disk)->delete($file->quarantine_path);
                    $stats['objects_deleted']++;
                }
                $file->update(['status' => 'rejected', 'rejection_code' => 'account_cancelled']);
            }
        });

        $job->update(['status' => 'completed', 'stats' => $stats, 'completed_at' => now(), 'last_error' => null]);

        return $stats;
    }

    public function replayManifests(): int
    {
        $count = 0;
        DataDeletionManifest::query()->orderBy('id')->chunkById(100, function ($manifests) use (&$count): void {
            foreach ($manifests as $manifest) {
                $user = User::query()->find($manifest->user_id);
                if ($user !== null) {
                    UserIdentity::query()->where('user_id', $user->id)->delete();
                    UserAddress::withTrashed()->where('user_id', $user->id)->forceDelete();
                    UserAssetAccount::query()->where('user_id', $user->id)->update([
                        'points_available' => 0, 'points_frozen' => 0, 'points_debt' => 0,
                        'wallet_available_cents' => 0, 'wallet_frozen_cents' => 0, 'coupon_available' => 0,
                    ]);
                    UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'deletion_replay', 'updated_at' => now()]);
                    $user->update(['nickname' => '已注销用户', 'avatar_file_id' => null, 'phone' => null, 'phone_hash' => null, 'status' => 'cancelled']);
                }
                $manifest->update(['last_replayed_at' => now()]);
                $count++;
            }
        });

        return $count;
    }
}
