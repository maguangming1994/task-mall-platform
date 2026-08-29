<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminAuthService
{
    public function __construct(
        private readonly AdminAccessTokenService $accessTokens,
        private readonly AdminLoginGuard $loginGuard,
        private readonly AdminNavigationService $navigation,
        private readonly AuditLogger $audit,
        private readonly AdminMfaService $mfa,
    ) {}

    /** @return array<string, mixed> */
    public function login(string $account, string $password, ?string $ip, ?string $userAgent): array
    {
        $account = mb_strtolower(trim($account));
        $this->loginGuard->ensureAllowed($account, $ip);

        $admin = AdminUser::query()->where('account', $account)->first();
        if ($admin === null || ! Hash::check($password, $admin->password)) {
            $this->loginGuard->recordFailure($account, $ip);
            throw new AdminAuthException('ADMIN_AUTH_INVALID_CREDENTIALS', '账号或密码错误。', 401);
        }

        if ($admin->status !== 'active') {
            throw new AdminAuthException('ADMIN_ACCOUNT_DISABLED', '后台账号当前不可登录。', 403);
        }

        $this->loginGuard->clear($account, $ip);

        if ($admin->requires_mfa) {
            return $this->mfa->startChallenge($admin);
        }

        $result = DB::transaction(function () use ($admin, $ip, $userAgent): array {
            $this->enforceSessionLimit($admin);
            $result = $this->createSession($admin, $ip, $userAgent);
            $admin->update(['last_login_at' => now(), 'last_verified_at' => now()]);

            return $result;
        });

        $this->audit->record($admin, 'admin.auth.login', 'admin_user', $admin->public_id);

        return $result;
    }

    /** @return array<string,mixed> */
    public function completeMfaLogin(AdminUser $admin, ?string $ip, ?string $userAgent): array
    {
        if ($admin->status !== 'active') {
            throw new AdminAuthException('ADMIN_ACCOUNT_DISABLED', '后台账号当前不可登录。', 403);
        }
        $result = DB::transaction(function () use ($admin, $ip, $userAgent): array {
            $this->enforceSessionLimit($admin);
            $result = $this->createSession($admin, $ip, $userAgent);
            $admin->update(['last_login_at' => now(), 'last_verified_at' => now()]);

            return $result;
        });
        $this->audit->record($admin, 'admin.auth.login_mfa', 'admin_user', $admin->public_id);

        return $result;
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, ?string $ip, ?string $userAgent): array
    {
        return DB::transaction(function () use ($refreshToken, $ip, $userAgent): array {
            $session = AdminSession::query()
                ->with('adminUser.roles.permissions')
                ->where('refresh_token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if (! $this->isUsable($session)) {
                throw new AdminAuthException('ADMIN_AUTH_SESSION_REVOKED', '后台登录状态无效或已过期。', 401);
            }

            $session->update([
                'revoked_at' => now(),
                'revoke_reason' => 'rotated',
            ]);

            $newRefreshToken = $this->randomToken();
            $newSession = AdminSession::query()->create([
                'admin_user_id' => $session->admin_user_id,
                'refresh_token_hash' => hash('sha256', $newRefreshToken),
                'issued_at' => $session->issued_at,
                'last_active_at' => now(),
                'verified_at' => $session->verified_at,
                'idle_expires_at' => $this->idleExpiry($session->expires_at),
                'expires_at' => $session->expires_at,
                'ip_hash' => $this->fingerprint($ip),
                'user_agent_hash' => $this->fingerprint($userAgent),
            ]);

            return $this->tokenPayload($session->adminUser, $newSession, $newRefreshToken);
        });
    }

    public function logout(AdminSession $session, bool $allSessions): void
    {
        $query = AdminSession::query()
            ->where('admin_user_id', $session->admin_user_id)
            ->whereNull('revoked_at');

        if (! $allSessions) {
            $query->whereKey($session->id);
        }

        $query->update([
            'revoked_at' => now(),
            'revoke_reason' => $allSessions ? 'logout_all' : 'logout',
            'updated_at' => now(),
        ]);

        $this->audit->record(
            $session->adminUser,
            $allSessions ? 'admin.auth.logout_all' : 'admin.auth.logout',
            'admin_session',
            $session->public_id,
        );
    }

    public function verifyRecent(AdminSession $session, string $password, ?string $mfaCode): void
    {
        $admin = $session->adminUser;
        if (! Hash::check($password, $admin->password)) {
            throw new AdminAuthException('ADMIN_AUTH_INVALID_CREDENTIALS', '密码不正确。', 422);
        }
        if ($admin->requires_mfa && ($mfaCode === null || ! $this->mfa->verifyFactor($admin, $mfaCode))) {
            throw new AdminAuthException('ADMIN_MFA_CODE_INVALID', '二次验证码或恢复码不正确。', 422);
        }
        $session->update(['verified_at' => now()]);
        $admin->update(['last_verified_at' => now()]);
        $this->audit->record($admin, 'admin.auth.reverified', 'admin_session', $session->public_id);
    }

    public function changePassword(AdminSession $session, string $currentPassword, string $newPassword, ?string $mfaCode): void
    {
        $this->verifyRecent($session, $currentPassword, $mfaCode);
        $admin = $session->adminUser;

        if (Hash::check($newPassword, $admin->password)) {
            throw new AdminAuthException('ADMIN_PASSWORD_UNCHANGED', '新密码不能与当前密码相同。', 422);
        }

        DB::transaction(function () use ($admin, $session, $newPassword): void {
            $admin->update(['password' => $newPassword, 'last_verified_at' => now()]);
            AdminSession::query()
                ->where('admin_user_id', $admin->id)
                ->whereKeyNot($session->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'password_changed',
                    'updated_at' => now(),
                ]);
        });

        $this->audit->record($admin, 'admin.auth.password_changed', 'admin_user', $admin->public_id);
    }

    /** @return array<string, mixed> */
    public function present(AdminUser $admin): array
    {
        $admin->loadMissing('campuses');
        $admin->loadMissing('roles');

        return [
            'id' => $admin->public_id,
            'account' => $admin->account,
            'display_name' => $admin->display_name,
            'email' => $admin->email,
            'status' => $admin->status,
            'requires_mfa' => $admin->requires_mfa,
            'roles' => $admin->roles->pluck('code')->sort()->values()->all(),
            'permissions' => $admin->permissionCodes(),
            'campuses' => $admin->campuses->map(fn ($campus) => [
                'id' => $campus->public_id,
                'code' => $campus->code,
                'name' => $campus->name,
                'is_primary' => (bool) $campus->pivot->is_primary,
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function navigation(AdminUser $admin): array
    {
        return $this->navigation->for($admin);
    }

    private function enforceSessionLimit(AdminUser $admin): void
    {
        $sessions = AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('revoked_at')
            ->orderBy('last_active_at')
            ->lockForUpdate()
            ->get();
        $limit = max(1, (int) config('admin_auth.max_active_sessions'));

        while ($sessions->count() >= $limit) {
            $oldest = $sessions->shift();
            $oldest?->update(['revoked_at' => now(), 'revoke_reason' => 'session_limit']);
        }
    }

    /** @return array<string, mixed> */
    private function createSession(AdminUser $admin, ?string $ip, ?string $userAgent): array
    {
        $refreshToken = $this->randomToken();
        $expiresAt = now()->addDays(max(1, (int) config('admin_auth.refresh_ttl_days')));
        $session = AdminSession::query()->create([
            'admin_user_id' => $admin->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'issued_at' => now(),
            'last_active_at' => now(),
            'verified_at' => now(),
            'idle_expires_at' => $this->idleExpiry($expiresAt),
            'expires_at' => $expiresAt,
            'ip_hash' => $this->fingerprint($ip),
            'user_agent_hash' => $this->fingerprint($userAgent),
        ]);

        return $this->tokenPayload($admin, $session, $refreshToken);
    }

    /** @return array<string, mixed> */
    private function tokenPayload(AdminUser $admin, AdminSession $session, string $refreshToken): array
    {
        $access = $this->accessTokens->issue($admin, $session);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            'admin' => $this->present($admin),
            'navigation' => $this->navigation($admin),
        ];
    }

    private function isUsable(?AdminSession $session): bool
    {
        return $session !== null
            && $session->revoked_at === null
            && ! $session->expires_at->isPast()
            && ! $session->idle_expires_at->isPast()
            && $session->adminUser->status === 'active';
    }

    private function idleExpiry(\DateTimeInterface $maxExpiry): \DateTimeInterface
    {
        $expiry = now()->addSeconds(max(60, (int) config('admin_auth.idle_ttl_seconds')));

        return $expiry->greaterThan($maxExpiry) ? $maxExpiry : $expiry;
    }

    private function fingerprint(?string $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
}
