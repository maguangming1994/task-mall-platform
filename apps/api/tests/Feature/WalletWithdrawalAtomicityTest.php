<?php

namespace Tests\Feature;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalletWithdrawalAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_with_different_payload_returns_stable_conflict_without_second_freeze(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $ledger = app(AssetLedgerService::class);
        $ledger->changeWallet($user, 10000, '初始化提现余额。', 'withdrawal-atomicity-balance');
        $service = app(WalletWithdrawalService::class);
        $service->create($user, 1000, 'wechat_transfer', 'withdrawal-idempotency-001');

        try {
            $service->create($user, 2000, 'wechat_transfer', 'withdrawal-idempotency-001');
            $this->fail('Expected an idempotency conflict.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', IdempotencyConflictException::ERROR_CODE);
            $this->assertSame(409, IdempotencyConflictException::STATUS);
            $this->assertSame('相同幂等键已绑定不同的提现请求，请更换幂等键。', $exception->getMessage());
        }

        $account = UserAssetAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(9000, $account->wallet_available_cents);
        $this->assertSame(1000, $account->wallet_frozen_cents);
        $this->assertSame(1, WalletWithdrawal::query()->where('user_id', $user->id)->count());
        $this->assertNotEmpty(WalletWithdrawal::query()->where('user_id', $user->id)->value('request_payload_hash'));
    }

    public function test_daily_limit_is_rechecked_for_each_locked_wallet_transaction(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $ledger = app(AssetLedgerService::class);
        $ledger->changeWallet($user, 10000, '初始化提现余额。', 'withdrawal-limit-balance');
        $service = app(WalletWithdrawalService::class);

        for ($index = 1; $index <= 3; $index++) {
            $service->create($user, 1000, 'wechat_transfer', "withdrawal-limit-00{$index}");
        }

        $this->expectException(ValidationException::class);
        $service->create($user, 1000, 'wechat_transfer', 'withdrawal-limit-004');
    }

    public function test_http_response_uses_stable_conflict_code_for_payload_reuse(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        app(AssetLedgerService::class)->changeWallet($user, 10000, '初始化提现余额。', 'withdrawal-http-balance');
        $token = $this->tokenFor($user);
        $headers = ['Idempotency-Key' => 'withdrawal-http-001'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 1000,
            'channel' => 'wechat_transfer',
        ])->assertCreated();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 2000,
            'channel' => 'wechat_transfer',
        ])->assertStatus(409)
            ->assertJsonPath('code', IdempotencyConflictException::ERROR_CODE)
            ->assertJsonPath('message', '相同幂等键已绑定不同的提现请求，请更换幂等键。');
    }

    private function tokenFor(User $user): string
    {
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'withdrawal-http-device'),
            'client_platform' => 'web_h5',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'withdrawal-http-refresh'),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return app(AccessTokenService::class)->issue($user, $session)['token'];
    }
}
