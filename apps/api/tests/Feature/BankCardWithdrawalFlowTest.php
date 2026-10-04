<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\WalletPayout;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Wallet\Models\UserBankCard;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankCardWithdrawalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_card_defaults_first_card_blocks_duplicates_and_enforces_verified_surname(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $token = $this->userToken($user);

        $this->withToken($token)->postJson('/api/v1/wallet/bank-cards', [
            'cardholder_name' => '张小明',
            'bank_name' => '招商银行',
            'card_number' => '6225880123456789',
            'phone' => '13800138000',
        ])->assertCreated()
            ->assertJsonPath('data.bank_name', '招商银行')
            ->assertJsonPath('data.card_tail', '6789')
            ->assertJsonPath('data.is_default', true);

        // 同银行同尾号视为重复绑卡
        $this->withToken($token)->postJson('/api/v1/wallet/bank-cards', [
            'cardholder_name' => '张小明',
            'bank_name' => '招商银行',
            'card_number' => '6225880123456789',
            'phone' => '13800138000',
        ])->assertUnprocessable()->assertJsonPath('details.errors.card_number.0', '该银行卡已绑定，请勿重复添加。');

        // 已实名（张*）的用户绑定他姓卡被拒绝
        UserIdentityVerification::query()->create([
            'user_id' => $user->id,
            'provider' => 'aliyun',
            'status' => 'verified',
            'real_name_masked' => '张**',
            'identity_number_masked' => '330***********1234',
            'identity_number_hash' => hash('sha256', 'identity-hash-zhang'),
            'verified_at' => now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/wallet/bank-cards', [
            'cardholder_name' => '李大勇',
            'bank_name' => '工商银行',
            'card_number' => '6222021234567890',
            'phone' => '13800138000',
        ])->assertUnprocessable()->assertJsonPath('details.errors.cardholder_name.0', '持卡人姓名需与实名认证姓名保持一致。');

        // 同姓不同卡可继续绑定，但不再自动成为默认卡
        $this->withToken($token)->postJson('/api/v1/wallet/bank-cards', [
            'cardholder_name' => '张伟',
            'bank_name' => '工商银行',
            'card_number' => '6222021234567890',
            'phone' => '13800138000',
        ])->assertCreated()->assertJsonPath('data.is_default', false);

        $this->assertSame(2, UserBankCard::query()->where('user_id', $user->id)->count());
    }

    public function test_bank_card_withdrawal_requires_owned_card_and_freezes_with_snapshot(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $token = $this->userToken($user);
        app(AssetLedgerService::class)->changeWallet($user, 10000, '初始化银行卡提现余额。', 'bank-withdrawal-balance');

        $cardId = $this->withToken($token)->postJson('/api/v1/wallet/bank-cards', [
            'cardholder_name' => '王芳',
            'bank_name' => '建设银行',
            'card_number' => '6217001234561234',
            'phone' => '13900139000',
        ])->assertCreated()->json('data.id');

        // 未选卡
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'bank-withdrawal-no-card-1'])->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 1000,
            'channel' => 'bank_card',
        ])->assertStatus(422);

        // 他人的卡（另一个用户绑定的同结构卡）
        $other = User::factory()->create();
        $otherCard = UserBankCard::query()->create([
            'user_id' => $other->id,
            'cardholder_name' => '赵六',
            'bank_name' => '中国银行',
            'card_tail' => '9999',
            'phone_masked' => '137****9999',
        ]);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'bank-withdrawal-other-1'])->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 1000,
            'channel' => 'bank_card',
            'bank_card_id' => $otherCard->public_id,
        ])->assertStatus(422);

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'bank-withdrawal-create-1'])->postJson('/api/v1/wallet/withdrawals', [
            'amount' => 1000,
            'channel' => 'bank_card',
            'bank_card_id' => $cardId,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'reviewing')
            ->assertJsonPath('data.channel_label', '银行卡')
            ->assertJsonPath('data.bank_card.bank_name', '建设银行')
            ->assertJsonPath('data.bank_card.card_tail', '1234');

        $withdrawal = WalletWithdrawal::query()->firstOrFail();
        $this->assertSame('bank_card', $withdrawal->channel);
        $this->assertSame($cardId, $withdrawal->channel_reference);
        $this->assertSame('建设银行', $withdrawal->bank_card_snapshot['bank_name']);

        $account = UserAssetAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(9000, $account->wallet_available_cents);
        $this->assertSame(1000, $account->wallet_frozen_cents);
    }

    public function test_bank_card_withdrawal_settles_via_manual_result_without_provider_dispatch(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $this->seed(RbacSeeder::class);
        $adminToken = $this->adminToken('bank-finance');

        $user = User::factory()->create();
        app(AssetLedgerService::class)->changeWallet($user, 20000, '初始化人工打款余额。', 'manual-payout-balance');
        $card = UserBankCard::query()->create([
            'user_id' => $user->id,
            'cardholder_name' => '陈晨',
            'bank_name' => '交通银行',
            'card_tail' => '4321',
            'phone_masked' => '136****4321',
        ]);

        $service = app(WalletWithdrawalService::class);
        $withdrawal = $service->create($user, 1000, 'bank_card', 'manual-payout-create-1', $card);

        // 审核通过：进入处理中，但不创建线上渠道出款单
        $reviewUrl = '/api/v1/admin/wallet-withdrawals/'.$withdrawal->public_id.'/review';
        $this->withToken($adminToken)->withHeaders(['Idempotency-Key' => 'manual-payout-review-1'])
            ->postJson($reviewUrl, ['decision' => 'approve', 'note' => '资料无误，转人工打款。'])
            ->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertSame(0, WalletPayout::query()->count());

        // 线上出款渠道接口对银行卡通道直接拒绝
        try {
            $service->dispatch($withdrawal->fresh());
            $this->fail('bank_card withdrawal must not be dispatched to the online provider.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('withdrawal', $exception->errors());
        }

        // 登记人工转账成功：扣减冻结、记录 manual_bank 出款单
        $this->withToken($adminToken)->postJson('/api/v1/admin/wallet-withdrawals/'.$withdrawal->public_id.'/manual-result', [
            'decision' => 'paid',
            'reference' => 'BK20261004000001',
        ])->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.channel_reference', 'BK20261004000001');

        $account = UserAssetAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(19000, $account->wallet_available_cents);
        $this->assertSame(0, $account->wallet_frozen_cents);
        $payout = WalletPayout::query()->firstOrFail();
        $this->assertSame('manual_bank', $payout->provider);
        $this->assertSame('succeeded', $payout->status);

        // 第二笔：人工打款失败时金额解冻
        $failed = $service->create($user, 1000, 'bank_card', 'manual-payout-create-2', $card);
        $this->withToken($adminToken)->withHeaders(['Idempotency-Key' => 'manual-payout-review-2'])
            ->postJson('/api/v1/admin/wallet-withdrawals/'.$failed->public_id.'/review', ['decision' => 'approve', 'note' => '通过。'])
            ->assertOk();
        $this->withToken($adminToken)->postJson('/api/v1/admin/wallet-withdrawals/'.$failed->public_id.'/manual-result', [
            'decision' => 'failed',
            'note' => '开户行信息有误，转账被退回。',
        ])->assertOk()->assertJsonPath('data.status', 'failed');

        $account->refresh();
        $this->assertSame(19000, $account->wallet_available_cents);
        $this->assertSame(0, $account->wallet_frozen_cents);
    }

    private function userToken(User $user): string
    {
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'bank-card-device-'.$user->id),
            'client_platform' => 'web_h5',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'bank-card-refresh-'.$user->id),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return app(AccessTokenService::class)->issue($user, $session)['token'];
    }

    private function adminToken(string $account): string
    {
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
