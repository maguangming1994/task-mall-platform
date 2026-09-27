<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Services\AdminMfaService;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TaskPublisherAuthService
{
    public function __construct(
        private readonly AdminMfaService $mfa,
        private readonly SystemSettingService $settings,
        private readonly TaskPublishingPermissionService $permissions,
    ) {}

    public function login(string $account, string $password, ?string $mfaCode, ?string $ip, ?string $userAgent): array
    {
        $this->ensurePublisherCenterEnabled();
        $admin = AdminUser::query()->with('roles.permissions', 'mfaFactor')->where('account', mb_strtolower(trim($account)))->first();
        if ($admin === null || $admin->is_service_account || ! Hash::check($password, $admin->password)) {
            throw new AdminAuthException('PUBLISHER_AUTH_INVALID', '发布者账号或密码错误。', 401);
        }
        if ($admin->status !== 'active' || ! $admin->roles->contains('code', 'platform_admin') || ! $admin->hasPermission('task.frontend_publish')) {
            throw new AdminAuthException('PUBLISHER_PERMISSION_DENIED', '该后台账号没有用户端任务发布权限。', 403);
        }
        if ($admin->requires_mfa && ($mfaCode === null || ! $this->mfa->verifyFactor($admin, $mfaCode))) {
            throw new AdminAuthException('PUBLISHER_MFA_INVALID', '发布者 MFA 验证失败。', 422);
        }
        $publisher = TaskPublisher::query()->firstOrCreate(
            ['publisher_type' => 'platform_admin', 'admin_user_id' => $admin->id],
            ['verification_status' => 'verified', 'publisher_level' => 'platform', 'status' => 'active'],
        );
        if ($publisher->status !== 'active') {
            throw new AdminAuthException('PUBLISHER_SUSPENDED', '该发布主体已被暂停。', 403);
        }

        return $this->issueSession($publisher, $ip, $userAgent);
    }

    public function loginUser(User $user, ?string $ip, ?string $userAgent): array
    {
        $this->ensurePublisherCenterEnabled();

        return DB::transaction(function () use ($user, $ip, $userAgent): array {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);
            if ($lockedUser === null || ! $this->permissions->userCanPublish($lockedUser)) {
                throw new AdminAuthException('PUBLISHER_PERMISSION_DENIED', '当前账号未获任务发布权限，请联系管理员开通。', 403);
            }

            $publisher = TaskPublisher::query()
                ->with(['adminUser', 'linkedUser'])
                ->where('publisher_type', 'user')
                ->where('linked_user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();
            if ($publisher === null || ! $this->permissions->publisherCanPublish($publisher)) {
                throw new AdminAuthException('PUBLISHER_PERMISSION_DENIED', '当前账号的任务发布权限不可用，请联系管理员处理。', 403);
            }

            return $this->issueSession($publisher, $ip, $userAgent);
        });
    }

    public function logout(TaskPublisherSession $session): void
    {
        $session->update(['revoked_at' => now()]);
    }

    public function present(TaskPublisher $publisher): array
    {
        $publisher->loadMissing(['adminUser', 'linkedUser']);
        $canPublish = $this->permissions->publisherCanPublish($publisher);
        $isUserPublisher = $publisher->publisher_type === 'user';

        return [
            'id' => $publisher->public_id,
            'publisher_type' => $publisher->publisher_type,
            'admin_display_name' => $isUserPublisher ? $publisher->linkedUser?->nickname : $publisher->adminUser->display_name,
            'role' => $isUserPublisher ? 'authorized_user' : 'platform_admin',
            'can_publish' => $canPublish,
            'permissions' => $canPublish ? ['task.frontend_publish'] : [],
        ];
    }

    public function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, hash('sha256', (string) config('publisher_auth.hash_secret')));
    }

    private function ensurePublisherCenterEnabled(): void
    {
        if (! $this->settings->read('tasks.frontend_publish_enabled')['value']) {
            throw new AdminAuthException('PUBLISHER_CENTER_DISABLED', '用户端任务发布中心当前已关闭。', 403);
        }
    }

    /** @return array<string, mixed> */
    private function issueSession(TaskPublisher $publisher, ?string $ip, ?string $userAgent): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $ttl = max(300, (int) config('publisher_auth.session_ttl_seconds'));
        $session = TaskPublisherSession::query()->create([
            'task_publisher_id' => $publisher->id,
            'token_hash' => $this->tokenHash($token),
            'last_active_at' => now(),
            'expires_at' => now()->addSeconds($ttl),
            'ip_hash' => $this->fingerprint($ip),
            'user_agent_hash' => $this->fingerprint($userAgent),
        ]);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'expires_at' => $session->expires_at->toIso8601String(),
            'publisher' => $this->present($publisher),
        ];
    }

    private function fingerprint(?string $value): ?string
    {
        return empty($value) ? null : hash_hmac('sha256', $value, hash('sha256', (string) config('publisher_auth.hash_secret')));
    }
}
