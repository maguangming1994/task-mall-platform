<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Campus\Services\CampusBrandingUrl;
use App\Modules\Privacy\Services\AccountCancellationService;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly AccessTokenService $accessTokens,
        private readonly PhoneVerificationService $phoneVerification,
        private readonly WechatIdentityProvider $wechat,
        private readonly IdentityHasher $identityHasher,
        private readonly IdentityConflictService $identityConflicts,
        private readonly AccountCancellationService $cancellations,
        private readonly SystemSettingService $settings,
        private readonly CampusBrandingUrl $campusBranding,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function loginWithWechat(
        string $provider,
        string $code,
        string $clientPlatform,
        string $deviceId,
        ?string $deviceName,
    ): array {
        $external = $this->wechat->exchange($provider, $code);
        $providerHash = $this->identityHasher->hash($external['openid']);
        $unionHash = $external['unionid'] === null
            ? null
            : $this->identityHasher->hash($external['unionid']);

        $exactIdentity = UserIdentity::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerHash)
            ->first();
        $unionUserIds = $unionHash === null
            ? collect()
            : UserIdentity::query()
                ->where('union_id', $unionHash)
                ->pluck('user_id')
                ->unique()
                ->values();

        $unionConflict = $unionUserIds->count() > 1
            || ($exactIdentity !== null
                && $unionUserIds->isNotEmpty()
                && ! $unionUserIds->contains($exactIdentity->user_id));

        if ($unionConflict) {
            $target = $unionUserIds->count() === 1
                ? User::query()->find($unionUserIds->first())
                : null;
            $conflict = $this->identityConflicts->record(
                'union_collision',
                $provider,
                $providerHash,
                $external['openid'],
                $unionHash,
                $external['unionid'],
                $exactIdentity?->user,
                $target,
                $unionUserIds->all(),
            );

            throw $this->identityConflicts->exception($conflict);
        }

        return DB::transaction(function () use (
            $provider,
            $providerHash,
            $unionHash,
            $external,
            $exactIdentity,
            $unionUserIds,
            $clientPlatform,
            $deviceId,
            $deviceName,
        ): array {
            $user = $exactIdentity?->user;
            if ($user === null && $unionUserIds->count() === 1) {
                $user = User::query()->find($unionUserIds->first());
            }
            if ($user === null) {
                $this->assertSelfRegistrationEnabled();
                $user = User::query()->create([
                    'nickname' => '微信用户'.strtoupper(substr($providerHash, 0, 6)),
                    'status' => 'active',
                ]);
            }
            $this->cancellations->withdrawOnLogin($user);
            if ($user->status !== 'active') {
                throw new AuthException('ACCOUNT_DISABLED', '账号当前不可登录。', 403);
            }

            $identity = UserIdentity::query()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $providerHash],
                [
                    'user_id' => $user->id,
                    'provider_user_value' => $external['openid'],
                    'union_id' => $unionHash,
                    'union_value' => $external['unionid'],
                    'metadata' => ['source' => 'wechat_code_exchange', 'app_id_hash' => $external['app_id_hash']],
                    'last_login_at' => now(),
                ],
            );

            $device = $this->resolveDevice($user, $clientPlatform, $deviceId, $deviceName);

            return $this->issueTokenPair($user, $device, $identity->id);
        });
    }

    /** @return array<string, mixed> */
    public function bindPhone(User $user, UserSession $session, string $phone, string $verificationCode): array
    {
        if (! $this->phoneVerification->verify($phone, 'bind_phone', $verificationCode)) {
            throw new AuthException('PHONE_VERIFICATION_FAILED', '验证码错误、已过期或尚未申请。', 422);
        }

        return $this->bindVerifiedPhone($user, $session, $phone, 'sms_verification');
    }

    /** @return array<string, mixed> */
    public function bindWechatPhone(User $user, UserSession $session, string $phone): array
    {
        return $this->bindVerifiedPhone($user, $session, $phone, 'wechat_phone_authorization');
    }

    /**
     * 换绑手机号：同时校验当前手机号与新手机号。
     *
     * @return array<string, mixed>
     */
    public function changePhone(
        User $user,
        UserSession $session,
        string $currentPhone,
        string $currentCode,
        string $newPhone,
        string $newCode,
    ): array {
        if (! $this->phoneVerification->verify($currentPhone, 'change_phone_old', $currentCode)) {
            throw new AuthException('PHONE_CHANGE_CURRENT_CODE_INVALID', '当前手机号验证码错误或已过期。', 422);
        }
        $currentHash = $this->phoneVerification->phoneHash($currentPhone);
        if ($user->phone_hash === null || $user->phone_hash !== $currentHash) {
            throw new AuthException('PHONE_CHANGE_CURRENT_MISMATCH', '当前手机号与账号绑定不一致，请重新登录后重试。', 422);
        }
        if (! $this->phoneVerification->verify($newPhone, 'change_phone_new', $newCode)) {
            throw new AuthException('PHONE_CHANGE_NEW_CODE_INVALID', '新手机号验证码错误或已过期。', 422);
        }
        if ($this->phoneVerification->phoneHash($newPhone) === $currentHash) {
            throw new AuthException('PHONE_CHANGE_SAME_NUMBER', '新手机号与当前手机号相同。', 422);
        }

        return $this->bindVerifiedPhone($user, $session, $newPhone, 'sms_change_phone', true);
    }

    /** @return array<string, mixed> */
    private function bindVerifiedPhone(User $user, UserSession $session, string $phone, string $source, bool $currentPhoneVerified = false): array
    {
        $phoneHash = $this->phoneVerification->phoneHash($phone);
        if (! $currentPhoneVerified && $user->phone_hash !== null && $user->phone_hash !== $phoneHash) {
            throw new AuthException(
                'PHONE_CHANGE_REQUIRES_CURRENT_VERIFICATION',
                '更换已绑定手机号需要同时验证当前手机号。',
                422,
            );
        }
        $owner = User::query()->where('phone_hash', $phoneHash)->first();
        if ($owner !== null && $owner->id !== $user->id) {
            $conflict = $this->identityConflicts->record(
                'phone_already_bound',
                'phone',
                $phoneHash,
                $phone,
                null,
                null,
                $user,
                $owner,
                [$owner->id],
            );

            return DB::transaction(function () use ($conflict, $user, $session): array {
                $session->loadMissing(['device', 'identity']);
                $resolved = $this->identityConflicts->resolveVerifiedPhoneOwnership($conflict, $user);
                $target = $resolved['target'];
                $this->cancellations->withdrawOnLogin($target);
                $device = $this->resolveDeviceFromExisting($target, $session->device);
                $tokens = $this->issueTokenPair($target, $device, $resolved['identity_id']);
                $tokens['account_resolution'] = [
                    'outcome' => 'merged_into_existing_account',
                    'message' => '手机号已关联原账户，已合并微信身份并为你切换登录。',
                    'conflict_id' => $resolved['conflict']->public_id,
                ];

                return $tokens;
            });
        }

        DB::transaction(function () use ($user, $phone, $phoneHash, $source): void {
            $previousPhoneHash = $user->phone_hash;
            $user->update([
                'phone' => $phone,
                'phone_hash' => $phoneHash,
                'phone_verified_at' => now(),
            ]);
            if ($previousPhoneHash !== null && $previousPhoneHash !== $phoneHash) {
                // 换绑后旧手机号不再属于该账号，移除旧登录标识避免历史身份残留。
                UserIdentity::query()
                    ->where('provider', 'phone')
                    ->where('provider_user_id', $previousPhoneHash)
                    ->where('user_id', $user->id)
                    ->delete();
            }
            UserIdentity::query()->updateOrCreate(
                ['provider' => 'phone', 'provider_user_id' => $phoneHash],
                ['user_id' => $user->id, 'metadata' => ['source' => $source], 'last_login_at' => now()],
            );
        });

        return $this->presentUser($user->fresh());
    }

    private function resolveDeviceFromExisting(User $user, UserDevice $sourceDevice): UserDevice
    {
        $device = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint_hash', $sourceDevice->device_fingerprint_hash)
            ->where('client_platform', $sourceDevice->client_platform)
            ->lockForUpdate()
            ->first();

        if ($device !== null) {
            if ($device->revoked_at !== null) {
                $this->enforceDeviceLimit($user);
            }
            $device->update([
                'device_name' => $sourceDevice->device_name ?? $device->device_name,
                'last_active_at' => now(),
                'revoked_at' => null,
            ]);

            return $device;
        }

        $this->enforceDeviceLimit($user);

        return UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => $sourceDevice->device_fingerprint_hash,
            'client_platform' => $sourceDevice->client_platform,
            'device_name' => $sourceDevice->device_name,
            'last_active_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function loginWithPhone(
        string $phone,
        string $verificationCode,
        string $clientPlatform,
        string $deviceId,
        ?string $deviceName,
    ): array {
        if (! $this->phoneVerification->verify($phone, 'login', $verificationCode)) {
            throw new AuthException(
                'PHONE_VERIFICATION_FAILED',
                '验证码错误、已过期或尚未申请。',
                422,
            );
        }

        return DB::transaction(function () use ($phone, $clientPlatform, $deviceId, $deviceName): array {
            $phoneHash = $this->phoneVerification->phoneHash($phone);
            $user = User::query()->where('phone_hash', $phoneHash)->lockForUpdate()->first();

            if ($user === null) {
                $this->assertSelfRegistrationEnabled();
                $user = User::query()->create([
                    'nickname' => '用户'.substr($phone, -4),
                    'phone' => $phone,
                    'phone_hash' => $phoneHash,
                    'phone_verified_at' => now(),
                    'status' => 'active',
                ]);
            }

            $this->cancellations->withdrawOnLogin($user);

            if ($user->status !== 'active') {
                throw new AuthException('ACCOUNT_DISABLED', '账号当前不可登录。', 403);
            }

            UserIdentity::query()->updateOrCreate(
                ['provider' => 'phone', 'provider_user_id' => $phoneHash],
                ['user_id' => $user->id, 'metadata' => [], 'last_login_at' => now()],
            );

            $device = $this->resolveDevice($user, $clientPlatform, $deviceId, $deviceName);

            return $this->issueTokenPair($user, $device);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken): array
    {
        return DB::transaction(function () use ($refreshToken): array {
            $session = UserSession::query()
                ->where('refresh_token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if ($session === null
                || $session->revoked_at !== null
                || $session->expires_at->isPast()
                || $session->device->revoked_at !== null
                || $session->user->status !== 'active') {
                throw new AuthException('AUTH_SESSION_REVOKED', '刷新令牌无效或已过期。', 401);
            }

            $session->update([
                'revoked_at' => now(),
                'revoke_reason' => 'rotated',
            ]);

            $session->device->update(['last_active_at' => now()]);

            return $this->issueTokenPair($session->user, $session->device, $session->user_identity_id);
        });
    }

    public function logout(UserSession $session, bool $allDevices): void
    {
        DB::transaction(function () use ($session, $allDevices): void {
            $query = UserSession::query()
                ->where('user_id', $session->user_id)
                ->whereNull('revoked_at');

            if (! $allDevices) {
                $query->whereKey($session->id);
            }

            $query->update([
                'revoked_at' => now(),
                'revoke_reason' => $allDevices ? 'logout_all' : 'logout',
                'updated_at' => now(),
            ]);

            if ($allDevices) {
                UserDevice::query()
                    ->where('user_id', $session->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentUser(User $user): array
    {
        $user->loadMissing(['campus.emblemAsset', 'campus.logoAsset']);
        $phone = $user->phone;
        $avatar = $user->avatar_file_id === null ? null : UploadedFile::query()->find($user->avatar_file_id);
        $identityVerification = $user->identityVerification()->first();

        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'avatar_file_id' => $avatar?->public_id,
            'avatar_url' => $avatar === null ? null : rtrim((string) config('app.url'), '/').route('user-avatars.show', ['file' => $avatar], false),
            'phone_masked' => is_string($phone) ? $this->maskPhone($phone) : null,
            'phone_bound' => $user->phone_verified_at !== null,
            'status' => $user->status,
            'profile_completed' => $user->profile_completed_at !== null,
            'can_publish_tasks' => $user->status === 'active' && $user->can_publish_tasks,
            'real_name_status' => $identityVerification?->status ?? 'not_submitted',
            'campus' => $user->campus === null ? null : [
                'id' => $user->campus->public_id,
                'code' => $user->campus->code,
                'name' => $user->campus->name,
                'emblem_url' => $this->campusBranding->resolve($user->campus->emblem_asset_id, $user->campus->emblemAsset, $user->campus->emblem_url),
                'logo_url' => $this->campusBranding->resolve($user->campus->logo_asset_id, $user->campus->logoAsset, $user->campus->logo_url),
                'province' => $user->campus->province,
                'city' => $user->campus->city,
                'district' => $user->campus->district,
                'address' => $user->campus->address,
                'latitude' => $user->campus->latitude,
                'longitude' => $user->campus->longitude,
                'distance_km' => null,
            ],
        ];
    }

    private function resolveDevice(
        User $user,
        string $clientPlatform,
        string $deviceId,
        ?string $deviceName,
    ): UserDevice {
        $fingerprint = hash_hmac(
            'sha256',
            $deviceId,
            hash('sha256', (string) config('auth_tokens.identity_hash_secret')),
        );
        $platform = match ($clientPlatform) {
            'wechat-mini' => 'wechat_mini',
            'h5-wechat' => 'wechat_h5',
            'h5-browser' => 'web_h5',
            default => throw ValidationException::withMessages([
                'client_platform' => ['当前登录方式不支持该客户端。'],
            ]),
        };

        $device = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint_hash', $fingerprint)
            ->where('client_platform', $platform)
            ->lockForUpdate()
            ->first();

        if ($device !== null) {
            if ($device->revoked_at !== null) {
                $this->enforceDeviceLimit($user);
            }
            $device->update([
                'device_name' => $deviceName ?? $device->device_name,
                'last_active_at' => now(),
                'revoked_at' => null,
            ]);

            return $device;
        }

        $this->enforceDeviceLimit($user);

        return UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => $fingerprint,
            'client_platform' => $platform,
            'device_name' => $deviceName,
            'last_active_at' => now(),
        ]);
    }

    private function enforceDeviceLimit(User $user): void
    {
        $activeDevices = UserDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderBy('last_active_at')
            ->lockForUpdate()
            ->get();
        $limit = max(1, (int) config('auth_tokens.max_active_devices'));

        if ($activeDevices->count() >= $limit) {
            if (config('auth_tokens.device_limit_strategy') !== 'revoke_oldest') {
                throw ValidationException::withMessages([
                    'device_id' => ['活跃设备数量已达到上限。'],
                ]);
            }

            $oldest = $activeDevices->first();
            $oldest->update(['revoked_at' => now()]);
            UserSession::query()
                ->where('user_device_id', $oldest->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'device_limit',
                    'updated_at' => now(),
                ]);
        }

    }

    /**
     * @return array<string, mixed>
     */
    private function issueTokenPair(User $user, UserDevice $device, ?int $userIdentityId = null): array
    {
        $refreshToken = $this->randomToken();
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'user_identity_id' => $userIdentityId,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'issued_at' => now(),
            'expires_at' => now()->addDays((int) config('auth_tokens.refresh_ttl_days')),
        ]);
        $access = $this->accessTokens->issue($user, $session);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            'user' => $this->presentUser($user),
        ];
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function assertSelfRegistrationEnabled(): void
    {
        try {
            $enabled = (bool) $this->settings->read('accounts.self_registration_enabled')['value'];
        } catch (\Throwable) {
            $enabled = true;
        }
        if (! $enabled) {
            throw new AuthException('SELF_REGISTRATION_DISABLED', '平台当前暂停新用户自主注册，已有账号仍可正常登录。', 403);
        }
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 7) {
            return substr($phone, 0, 2).'***'.substr($phone, -2);
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
