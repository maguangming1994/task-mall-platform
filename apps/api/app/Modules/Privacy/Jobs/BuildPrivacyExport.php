<?php

namespace App\Modules\Privacy\Jobs;

use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuildPrivacyExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $privacyRequestId)
    {
        $this->onQueue('privacy');
    }

    public function handle(): void
    {
        $request = PrivacyRequest::query()->with(['user.identities', 'user.devices'])->find($this->privacyRequestId);
        if ($request === null || ! in_array($request->status, ['submitted', 'processing'], true)) {
            return;
        }
        $request->update(['status' => 'processing']);
        $user = $request->user;
        $payload = [
            'generated_at' => now()->toIso8601String(), 'request_id' => $request->public_id, 'scopes' => $request->scopes,
            'profile' => ['public_id' => $user->public_id, 'nickname' => $user->nickname, 'phone' => $user->phone, 'status' => $user->status, 'created_at' => $user->created_at?->toIso8601String()],
            'identities' => $user->identities->map(fn ($identity) => ['provider' => $identity->provider, 'linked_at' => $identity->created_at?->toIso8601String()])->all(),
            'devices' => $user->devices->map(fn ($device) => ['id' => $device->public_id, 'platform' => $device->client_platform, 'last_active_at' => $device->last_active_at?->toIso8601String(), 'revoked_at' => $device->revoked_at?->toIso8601String()])->all(),
            'addresses' => UserAddress::query()->where('user_id', $user->id)->get()->map(fn ($address) => [
                'recipient_name' => $address->recipient_name, 'phone' => $address->phone, 'province' => $address->province,
                'city' => $address->city, 'district' => $address->district, 'detail' => $address->detail, 'postal_code' => $address->postal_code,
            ])->all(),
            'asset_overview' => UserAssetAccount::query()->where('user_id', $user->id)->first()?->only([
                'points_available', 'points_frozen', 'points_debt', 'wallet_available_cents', 'wallet_frozen_cents', 'coupon_available',
            ]),
        ];
        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $disk = (string) config('uploads.disk');
        $path = 'privacy-exports/'.now()->format('Y/m/d').'/'.Str::uuid().'.json';
        Storage::disk($disk)->put($path, $contents, ['visibility' => 'private']);
        $expiresAt = now()->addDays(7);
        $file = UploadedFile::query()->create([
            'owner_user_id' => $user->id, 'purpose' => 'privacy_export', 'original_name' => '个人数据副本-'.$request->public_id.'.json',
            'extension' => 'json', 'declared_mime' => 'application/json', 'detected_mime' => 'application/json',
            'declared_size' => strlen($contents), 'actual_size' => strlen($contents), 'sha256' => hash('sha256', $contents),
            'disk' => $disk, 'quarantine_path' => $path, 'status' => 'clean', 'idempotency_key_hash' => hash('sha256', 'privacy-export:'.$request->id),
            'ticket_expires_at' => $expiresAt, 'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
        $request->update(['status' => 'completed', 'result_file_id' => $file->id, 'result_expires_at' => $expiresAt, 'completed_at' => now()]);
    }
}
