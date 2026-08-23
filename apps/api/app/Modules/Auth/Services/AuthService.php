<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
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

            UserIdentity::query()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $providerHash],
                [
                    'user_id' => $user->id,
                    'provider_user_value' => $external['openid'],
                    'union_id' => $unionHash,
                    'union_value' => $external['unionid'],
                    'metadata' => ['source' => 'wechat_code_exchange'],
                    'last_login_at' => now(),
                ],
            );

            $device = $this->resolveDevice($user, $clientPlatform, $deviceId, $deviceName);

            return $this->issueTokenPair($user, $device);
        });
    }

    /** @return array<string, mixed> */
    public function bindPhone(User $user, string $phone, string $verificationCode): array
    {
        if (! $this->phoneVerification->verify($phone, 'bind_phone', $verificationCode)) {
            throw new AuthException('PHONE_VERIFICATION_FAILED', '验证码错误、已过期或尚未申请。', 422);
        }

        return $this->bindVerifiedPhone($user, $phone, 'sms_verification');
    }

    /** @return array<string, mixed> */
    public function bindWechatPhone(User $user, string $phone): array
    {
        return $this->bindVerifiedPhone($user, $phone, 'wechat_phone_authorization');
    }

    /** @return array<string, mixed> */
    private function bindVerifiedPhone(User $user, string $phone, string $source): array
    {
        $phoneHash = $this->phoneVerification->phoneHash($phone);
        if ($user->phone_hash !== null && $user->phone_hash !== $phoneHash) {
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

            throw $this->identityConflicts->exception($conflict);
        }

        DB::transaction(function () use ($user, $phone, $phoneHash, $source): void {
            $user->update([
                'phone' => $phone,
                'phone_hash' => $phoneHash,
                'phone_verified_at' => now(),
            ]);
            UserIdentity::query()->updateOrCreate(
                ['provider' => 'phone', 'provider_user_id' => $phoneHash],
                ['user_id' => $user->id, 'metadata' => ['source' => $source], 'last_login_at' => now()],
            );
        });

        return $this->presentUser($user->fresh());
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

            return $this->issueTokenPair($session->user, $session->device);
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
        $phone = $user->phone;
        $avatar = $user->avatar_file_id === null ? null : UploadedFile::query()->find($user->avatar_file_id);

        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'avatar_file_id' => $avatar?->public_id,
            'avatar_url' => $avatar === null ? null : '/api/v1/files/'.$avatar->public_id.'/content',
            'phone_masked' => is_string($phone) ? $this->maskPhone($phone) : null,
            'phone_bound' => $user->phone_verified_at !== null,
            'status' => $user->status,
            'profile_completed' => $user->profile_completed_at !== null,
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
    private function issueTokenPair(User $user, UserDevice $device): array
    {
        $refreshToken = $this->randomToken();
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
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
