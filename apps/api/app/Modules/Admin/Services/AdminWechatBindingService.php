<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\AdminWechatBinding;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Services\WechatIdentityProvider;
use Illuminate\Support\Facades\DB;

/**
 * 管理员微信绑定：公众号 openid 与后台账号的一对一关系。
 *
 * 绑定后可在公众号 H5 内免密登录；开启二次验证的账号仍需动态码，
 * 免密只替代「账号密码」这一步，不替代 MFA。
 */
class AdminWechatBindingService
{
    public function __construct(
        private readonly WechatIdentityProvider $wechat,
        private readonly AuditLogger $audit,
    ) {}

    public function bind(AdminUser $admin, string $code): AdminWechatBinding
    {
        $identity = $this->identity($code);

        return DB::transaction(function () use ($admin, $identity): AdminWechatBinding {
            $occupied = AdminWechatBinding::query()
                ->where('openid_hash', $identity['openid_hash'])
                ->where('app_id_hash', $identity['app_id_hash'])
                ->where('admin_user_id', '!=', $admin->id)
                ->lockForUpdate()
                ->first();

            if ($occupied !== null) {
                throw new AdminAuthException('WECHAT_ALREADY_BOUND', '该微信已绑定其他管理员账号，请先解绑。', 409);
            }

            $binding = AdminWechatBinding::query()->updateOrCreate(
                ['admin_user_id' => $admin->id],
                [
                    'openid_hash' => $identity['openid_hash'],
                    'app_id_hash' => $identity['app_id_hash'],
                    'bound_at' => now(),
                    'last_used_at' => null,
                    'expires_at' => now()->addDays(max(1, (int) config('admin_auth.wechat_binding_ttl_days'))),
                ],
            );

            $this->audit->record($admin, 'admin.auth.wechat_bound', 'admin_user', $admin->public_id);

            return $binding;
        });
    }

    public function resolve(string $code): AdminUser
    {
        $identity = $this->identity($code);

        $binding = AdminWechatBinding::query()
            ->with('adminUser')
            ->where('openid_hash', $identity['openid_hash'])
            ->where('app_id_hash', $identity['app_id_hash'])
            ->first();

        if ($binding === null || ($binding->expires_at !== null && $binding->expires_at->isPast())) {
            throw new AdminAuthException('WECHAT_BINDING_NOT_FOUND', '当前微信尚未绑定管理员账号，请先用账号密码登录。', 401);
        }

        $admin = $binding->adminUser;
        if ($admin === null || $admin->status !== 'active' || $admin->is_service_account) {
            throw new AdminAuthException('ADMIN_ACCOUNT_DISABLED', '后台账号当前不可登录。', 403);
        }

        $binding->update(['last_used_at' => now()]);

        return $admin;
    }

    public function unbind(AdminUser $admin): void
    {
        DB::transaction(function () use ($admin): void {
            AdminWechatBinding::query()->where('admin_user_id', $admin->id)->delete();

            // 解绑后同步撤销通过微信建立的会话，防止旧设备继续使用免密登录态
            AdminSession::query()
                ->where('admin_user_id', $admin->id)
                ->where('login_method', 'wechat')
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'wechat_unbound',
                    'updated_at' => now(),
                ]);

            $this->audit->record($admin, 'admin.auth.wechat_unbound', 'admin_user', $admin->public_id);
        });
    }

    /** @return array{bound:bool,bound_at:?string,last_used_at:?string,expires_at:?string} */
    public function present(AdminUser $admin): array
    {
        $binding = AdminWechatBinding::query()->where('admin_user_id', $admin->id)->first();

        return [
            'bound' => $binding !== null && ($binding->expires_at === null || ! $binding->expires_at->isPast()),
            'bound_at' => $binding?->bound_at?->toIso8601String(),
            'last_used_at' => $binding?->last_used_at?->toIso8601String(),
            'expires_at' => $binding?->expires_at?->toIso8601String(),
        ];
    }

    /** @return array{openid_hash:string,app_id_hash:string} */
    private function identity(string $code): array
    {
        try {
            $identity = $this->wechat->exchange('h5', $code);
        } catch (AuthException $exception) {
            throw new AdminAuthException(
                'WECHAT_AUTH_FAILED',
                $exception->status >= 500 ? '微信登录服务暂不可用。' : '微信授权校验失败，请重新发起。',
                $exception->status >= 500 ? 503 : 422,
            );
        }

        return [
            'openid_hash' => hash_hmac('sha256', (string) $identity['openid'], (string) config('admin_auth.hash_secret')),
            'app_id_hash' => (string) $identity['app_id_hash'],
        ];
    }
}
