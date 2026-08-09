<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminAuthException;
use Illuminate\Support\Facades\Cache;

class AdminLoginGuard
{
    public function ensureAllowed(string $account, ?string $ip): void
    {
        $state = Cache::get($this->key($account, $ip), []);
        if (($state['locked_until'] ?? 0) > now()->timestamp) {
            throw new AdminAuthException('ADMIN_AUTH_LOCKED', '登录失败次数过多，请稍后再试。', 429);
        }
    }

    public function recordFailure(string $account, ?string $ip): void
    {
        $key = $this->key($account, $ip);
        $state = Cache::get($key, ['attempts' => 0, 'locked_until' => 0]);
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $threshold = max(1, (int) config('admin_auth.failure_threshold'));

        Cache::put($key, [
            'attempts' => $attempts,
            'locked_until' => $attempts >= $threshold
                ? now()->addSeconds(max(1, (int) config('admin_auth.lock_seconds')))->timestamp
                : 0,
        ], max(
            (int) config('admin_auth.failure_window_seconds'),
            (int) config('admin_auth.lock_seconds'),
        ));
    }

    public function clear(string $account, ?string $ip): void
    {
        Cache::forget($this->key($account, $ip));
    }

    private function key(string $account, ?string $ip): string
    {
        $identity = mb_strtolower(trim($account)).'|'.($ip ?? 'unknown');

        return 'admin-login:'.hash_hmac('sha256', $identity, $this->hashKey());
    }

    private function hashKey(): string
    {
        return hash('sha256', (string) config('admin_auth.hash_secret'));
    }
}
