<?php

namespace Tests\Feature;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Assets\Services\WalletRechargeService;
use App\Modules\Payment\Services\PaymentNotificationService;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletRechargeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_card_offline_and_online_recharges_credit_once_and_keep_a_ledger(): void
    {
        $service = app(WalletRechargeService::class);
        $admin = AdminUser::query()->create(['account' => 'recharge-finance', 'display_name' => '充值财务', 'password' => 'AdminPassword12345!', 'last_verified_at' => now()]);
        $user = User::factory()->create();

        $batch = $service->createCards($admin, 5000, 1, now()->addMonth()->toIso8601String());
        $card = $service->create($user, ['method' => 'card_code', 'card_code' => $batch['cards'][0]['code']], 'card-redeem-0001');
        $sameCard = $service->create($user, ['method' => 'card_code', 'card_code' => $batch['cards'][0]['code']], 'card-redeem-0001');
        $this->assertSame($card->public_id, $sameCard->public_id);
        $this->assertSame(5000, UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents'));

        $proof = UploadedFile::query()->create(['owner_user_id' => $user->id, 'purpose' => 'offline_recharge', 'original_name' => 'proof.jpg', 'extension' => 'jpg', 'declared_mime' => 'image/jpeg', 'detected_mime' => 'image/jpeg', 'declared_size' => 10, 'actual_size' => 10, 'sha256' => hash('sha256', 'proof'), 'disk' => 'local', 'quarantine_path' => 'proof.jpg', 'status' => 'clean', 'upload_token_hash' => hash('sha256', 'token'), 'idempotency_key_hash' => hash('sha256', 'proof-upload'), 'ticket_expires_at' => now()->addHour(), 'uploaded_at' => now(), 'scanned_at' => now()]);
        $offline = $service->create($user, ['method' => 'offline', 'amount_cents' => 2500, 'payer_name' => '测试同学', 'offline_reference' => 'BANK-001', 'offline_proof_file_ids' => [$proof->public_id]], 'offline-recharge-0001');
        $this->assertSame('pending_review', $offline->status);
        $service->review($admin, $offline, 'approve', '银行流水与凭证核验一致。');
        $this->assertSame(7500, UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents'));

        $online = $service->create($user, ['method' => 'online', 'amount_cents' => 1000], 'online-recharge-0001');
        $payment = app(PaymentService::class)->createRecharge($online, $user, 'h5-browser', 'online-payment-0001');
        $trade = ['notification_id' => 'wallet-recharge-notify-1', 'event_type' => 'TRANSACTION.SUCCESS', 'payment_no' => $payment->payment_no, 'provider_trade_no' => 'WX-RECHARGE-1', 'trade_state' => 'SUCCESS', 'cash_amount' => 1000, 'success_time' => now()->toIso8601String(), 'raw_resource' => ['amount' => ['total' => 1000]]];
        app(PaymentNotificationService::class)->process($trade);
        app(PaymentNotificationService::class)->process($trade);

        $this->assertSame(8500, UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents'));
        $this->assertSame(3, WalletTransaction::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'transaction_type' => 'recharge_card', 'change_available_cents' => 5000]);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'transaction_type' => 'recharge_offline', 'change_available_cents' => 2500]);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'transaction_type' => 'recharge_online', 'change_available_cents' => 1000]);
        $this->assertDatabaseHas('wallet_recharges', ['id' => $online->id, 'status' => 'succeeded']);
    }

    public function test_same_recharge_key_with_a_different_method_or_amount_is_a_conflict(): void
    {
        $service = app(WalletRechargeService::class);
        $user = User::factory()->create();
        $service->create($user, ['method' => 'online', 'amount_cents' => 1000], 'recharge-fingerprint-001');

        try {
            $service->create($user, ['method' => 'online', 'amount_cents' => 2000], 'recharge-fingerprint-001');
            $this->fail('Expected an idempotency conflict.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', $exception::ERROR_CODE);
            $this->assertSame(409, $exception::STATUS);
            $this->assertSame('相同幂等键已绑定不同的充值请求，请更换幂等键。', $exception->getMessage());
        }
    }
}
