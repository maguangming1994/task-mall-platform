<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalRulesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_endpoint_publishes_full_withdrawal_policy(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $token = $this->userToken(User::factory()->create());

        $this->withToken($token)->getJson('/api/v1/wallet/withdrawal-rules')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.minimum_amount', 1)
            ->assertJsonPath('data.maximum_amount', 100000)
            ->assertJsonPath('data.fee_rate', 6000)
            ->assertJsonPath('data.daily_count_limit', 3)
            ->assertJsonPath('data.daily_amount_limit', 200000)
            ->assertJsonPath('data.today_count', 0)
            ->assertJsonPath('data.today_amount', 0)
            ->assertJsonPath('data.daily_count_remaining', 3)
            ->assertJsonPath('data.review_mode', 'manual')
            ->assertJsonPath('data.channels.0.code', 'wechat_transfer')
            ->assertJsonPath('data.channels.1.code', 'bank_card');
    }

    public function test_one_cent_withdrawal_is_quotable_with_zero_fee(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $token = $this->userToken(User::factory()->create());

        // 0.01 元达到最低门槛，实算手续费不足 1 分时免收，确保门槛金额可提现。
        $this->withToken($token)->getJson('/api/v1/wallet/withdrawal-quote?amount=1')
            ->assertOk()
            ->assertJsonPath('data.requested_amount', 1)
            ->assertJsonPath('data.fee_amount', 0)
            ->assertJsonPath('data.received_amount', 1)
            ->assertJsonPath('data.minimum_amount', 1);
    }

    public function test_out_of_range_amount_is_rejected_with_yuan_message(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $token = $this->userToken(User::factory()->create());

        $this->withToken($token)->getJson('/api/v1/wallet/withdrawal-quote?amount=500001')
            ->assertStatus(422)
            ->assertJsonPath('details.errors.amount.0', '提现金额需在 0.01 元至 1000.00 元之间。');
    }

    public function test_rules_reflect_todays_usage_after_a_withdrawal(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $token = $this->userToken($user);
        app(AssetLedgerService::class)->changeWallet($user, 5000, '初始化提现规则测试余额。', 'withdrawal-rules-balance');

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'withdrawal-rules-create-1'])->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 1000,
            'channel' => 'wechat_transfer',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/wallet/withdrawal-rules')
            ->assertOk()
            ->assertJsonPath('data.today_count', 1)
            ->assertJsonPath('data.today_amount', 1000)
            ->assertJsonPath('data.daily_count_remaining', 2)
            ->assertJsonPath('data.daily_amount_remaining', 199000);
    }

    private function userToken(User $user): string
    {
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'withdrawal-rules-device-'.$user->id),
            'client_platform' => 'web_h5',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'withdrawal-rules-refresh-'.$user->id),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return app(AccessTokenService::class)->issue($user, $session)['token'];
    }
}
