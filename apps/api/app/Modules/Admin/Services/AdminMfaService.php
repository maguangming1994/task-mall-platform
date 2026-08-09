<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Models\AdminMfaChallenge;
use App\Modules\Admin\Models\AdminMfaFactor;
use App\Modules\Admin\Models\AdminMfaRecoveryCode;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminMfaService
{
    public function __construct(private readonly TotpService $totp) {}

    /** @return array{secret:string,otpauth_uri:string} */
    public function enroll(AdminUser $admin): array
    {
        $secret = $this->totp->generateSecret();
        AdminMfaFactor::query()->updateOrCreate(
            ['admin_user_id' => $admin->id],
            ['type' => 'totp', 'secret_ciphertext' => Crypt::encryptString($secret), 'status' => 'pending', 'confirmed_at' => null, 'last_used_at' => null],
        );
        $issuer = rawurlencode((string) config('app.name'));
        $label = rawurlencode(config('app.name').':'.$admin->account);

        return ['secret' => $secret, 'otpauth_uri' => "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30"];
    }

    /** @return array<int,string> */
    public function confirm(AdminUser $admin, string $code): array
    {
        $factor = $admin->mfaFactor()->where('status', 'pending')->first();
        if ($factor === null || ! $this->totp->verify(Crypt::decryptString($factor->secret_ciphertext), $code)) {
            throw new AdminAuthException('ADMIN_MFA_CODE_INVALID', '二次验证码不正确。', 422);
        }

        return DB::transaction(function () use ($admin, $factor): array {
            $codes = collect(range(1, 8))->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))->all();
            AdminMfaRecoveryCode::query()->where('admin_user_id', $admin->id)->delete();
            foreach ($codes as $code) {
                AdminMfaRecoveryCode::query()->create(['admin_user_id' => $admin->id, 'code_hash' => $this->fingerprint($code)]);
            }
            $factor->update(['status' => 'active', 'confirmed_at' => now(), 'last_used_at' => now()]);
            $admin->update(['requires_mfa' => true, 'last_verified_at' => now()]);

            return $codes;
        });
    }

    /** @return array<string,mixed> */
    public function startChallenge(AdminUser $admin): array
    {
        if (! $admin->mfaFactor()->where('status', 'active')->exists()) {
            throw new AdminAuthException('ADMIN_MFA_SETUP_REQUIRED', '账号要求二次验证，但尚未配置有效验证器，请联系超级管理员重置。', 403);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = AdminMfaChallenge::query()->create([
            'admin_user_id' => $admin->id,
            'challenge_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(5),
        ]);

        return ['mfa_required' => true, 'challenge_id' => $challenge->public_id, 'challenge_token' => $token, 'expires_at' => $challenge->expires_at->toIso8601String()];
    }

    public function verifyChallenge(AdminMfaChallenge $challenge, string $token, string $code): AdminUser
    {
        $result = DB::transaction(function () use ($challenge, $token, $code): array {
            $challenge = AdminMfaChallenge::query()->with('adminUser.mfaFactor')->lockForUpdate()->findOrFail($challenge->id);
            if ($challenge->consumed_at !== null || $challenge->expires_at->isPast() || ! hash_equals($challenge->challenge_token_hash, hash('sha256', $token))) {
                throw new AdminAuthException('ADMIN_MFA_CHALLENGE_INVALID', '二次验证挑战无效或已过期。', 403);
            }
            if ($challenge->attempts >= 5) {
                throw new AdminAuthException('ADMIN_MFA_CHALLENGE_LOCKED', '二次验证失败次数过多，请重新登录。', 429);
            }
            if (! $this->verifyFactor($challenge->adminUser, $code)) {
                $challenge->increment('attempts');

                return ['admin' => null];
            }
            $challenge->update(['consumed_at' => now()]);

            return ['admin' => $challenge->adminUser];
        });
        if (! $result['admin'] instanceof AdminUser) {
            throw new AdminAuthException('ADMIN_MFA_CODE_INVALID', '二次验证码或恢复码不正确。', 422);
        }

        return $result['admin'];
    }

    public function verifyFactor(AdminUser $admin, string $code): bool
    {
        $factor = $admin->mfaFactor;
        if ($factor !== null && $factor->status === 'active' && $this->totp->verify(Crypt::decryptString($factor->secret_ciphertext), $code)) {
            $factor->update(['last_used_at' => now()]);

            return true;
        }
        $recovery = AdminMfaRecoveryCode::query()->where('admin_user_id', $admin->id)->where('code_hash', $this->fingerprint(strtoupper($code)))->whereNull('used_at')->first();
        if ($recovery !== null) {
            $recovery->update(['used_at' => now()]);

            return true;
        }

        return false;
    }

    public function reset(AdminUser $admin): void
    {
        DB::transaction(function () use ($admin): void {
            AdminMfaChallenge::query()->where('admin_user_id', $admin->id)->delete();
            AdminMfaRecoveryCode::query()->where('admin_user_id', $admin->id)->delete();
            AdminMfaFactor::query()->where('admin_user_id', $admin->id)->delete();
            $admin->update(['requires_mfa' => false]);
        });
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
