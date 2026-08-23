<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\WechatPayV3Gateway;
use App\Modules\Refund\Models\Refund;
use App\Modules\Refund\Services\RefundService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialChannelSettlementFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_fake_wechat_refund_gateway_is_idempotent_and_only_settles_after_success(): void
    {
        [$user, $order, $refund] = $this->externalRefundFixture();
        $gateway = new FakeWechatRefundGateway;
        $this->app->instance(WechatPaymentGateway::class, $gateway);
        $service = app(RefundService::class);

        $service->dispatchProviderRefund($refund);
        $this->assertDatabaseHas('refund_components', ['refund_id' => $refund->id, 'status' => 'processing', 'provider_refund_no' => 'wx-refund-1']);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'processing']);
        $this->assertSame(0, $order->fresh()->refunded_external_amount);

        $gateway->refundState = 'SUCCESS';
        $service->syncProviderRefund($refund->fresh());
        $service->processProviderResult($gateway->queryRefund($refund->fresh()));

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'succeeded']);
        $this->assertSame(1000, $order->fresh()->refunded_external_amount);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(2, DB::table('refund_provider_events')->where('refund_id', $refund->id)->where('status', 'processed')->count());
        $this->assertSame(0, app(AssetLedgerService::class)->account($user)->wallet_frozen_cents);
    }

    public function test_missing_wechat_refund_certificates_block_without_fake_success(): void
    {
        config()->set('wechat_payment.merchant_id', null);
        $gateway = app(WechatPayV3Gateway::class);

        try {
            $gateway->refund(new Payment, new Refund);
            $this->fail('Expected missing production refund credentials to block the request.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('已阻止退款', $exception->errors()['refund'][0]);
        }
    }

    public function test_fake_payout_provider_consumes_frozen_balance_on_success_and_unfreezes_on_failure(): void
    {
        $provider = new FakePayoutProvider;
        $this->app->instance(PayoutProvider::class, $provider);
        $ledger = app(AssetLedgerService::class);
        $service = app(WalletWithdrawalService::class);
        $admin = AdminUser::query()->create(['account' => 'finance-test', 'display_name' => 'Finance Test', 'email' => 'finance@example.test', 'password' => 'secret-password', 'status' => 'active']);

        $successUser = User::factory()->create();
        $ledger->changeWallet($successUser, 10_000, '测试入账', 'payout-test-credit-1');
        $success = $service->create($successUser, 2_000, 'wechat', 'payout-create-key-0001');
        $service->review($admin, $success, 'approve', '审核通过', 'payout-review-key-0001');
        $this->assertSame(8_000, $ledger->account($successUser)->wallet_available_cents);
        $this->assertSame(2_000, $ledger->account($successUser)->wallet_frozen_cents);

        $provider->state = 'SUCCESS';
        $service->sync($success->fresh());
        $service->processProviderResult($provider->query($success->fresh()));
        $this->assertSame(8_000, $ledger->account($successUser)->wallet_available_cents);
        $this->assertSame(0, $ledger->account($successUser)->wallet_frozen_cents);
        $this->assertDatabaseHas('wallet_withdrawals', ['id' => $success->id, 'status' => 'succeeded', 'channel_reference' => 'payout-ref-1']);

        $failedUser = User::factory()->create();
        $ledger->changeWallet($failedUser, 10_000, '测试入账', 'payout-test-credit-2');
        $provider->state = 'PROCESSING';
        $failed = $service->create($failedUser, 2_000, 'wechat', 'payout-create-key-0002');
        $service->review($admin, $failed, 'approve', '审核通过', 'payout-review-key-0002');
        $provider->state = 'FAILED';
        $service->sync($failed->fresh());

        $this->assertSame(10_000, $ledger->account($failedUser)->wallet_available_cents);
        $this->assertSame(0, $ledger->account($failedUser)->wallet_frozen_cents);
        $this->assertDatabaseHas('wallet_withdrawals', ['id' => $failed->id, 'status' => 'failed']);
        $this->assertDatabaseHas('wallet_payouts', ['wallet_withdrawal_id' => $failed->id, 'status' => 'failed']);
    }

    /** @return array{0:User,1:Order,2:Refund} */
    private function externalRefundFixture(): array
    {
        $user = User::factory()->create();
        $previewId = DB::table('checkout_previews')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'preview-token'),
            'request_hash' => hash('sha256', 'preview-request'),
            'request_snapshot' => '{}',
            'pricing_snapshot' => '{}',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchId = DB::table('order_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'batch_no' => 'BATCH-FINANCE-1',
            'user_id' => $user->id,
            'client_platform' => 'h5',
            'capability_version' => 1,
            'status' => 'paid',
            'idempotency_key_hash' => hash('sha256', 'batch-key'),
            'checkout_preview_id' => $previewId,
            'items_cash_amount' => 1000,
            'items_points' => 0,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 0,
            'payable_cash_amount' => 1000,
            'payable_points' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORDER-FINANCE-1',
            'order_batch_id' => $batchId,
            'user_id' => $user->id,
            'fulfillment_type' => 'no_logistics',
            'status' => 'completed',
            'cash_payment_method' => 'wechat',
            'items_cash_amount' => 1000,
            'items_points' => 0,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 0,
            'payable_cash_amount' => 1000,
            'payable_points' => 0,
            'paid_external_amount' => 1000,
            'paid_at' => now(),
            'completed_at' => now(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAY-FINANCE-1',
            'order_id' => $order->id,
            'user_id' => $user->id,
            'channel' => 'wechat',
            'scene' => 'h5-browser',
            'cash_amount' => 1000,
            'status' => 'succeeded',
            'provider_trade_no' => 'wx-trade-1',
            'idempotency_key_hash' => hash('sha256', 'payment-key'),
            'expires_at' => now()->addHour(),
            'succeeded_at' => now(),
        ]);
        $refund = Refund::query()->create([
            'refund_no' => 'REFUND-FINANCE-1',
            'order_id' => $order->id,
            'user_id' => $user->id,
            'service_type' => 'refund_only',
            'reason_code' => 'buyer_request',
            'status' => 'processing',
            'refund_cash_amount' => 1000,
            'refund_wallet_amount' => 0,
            'refund_external_amount' => 1000,
            'refund_points' => 0,
            'idempotency_key_hash' => hash('sha256', 'refund-key'),
        ]);
        DB::table('refund_components')->insert([
            'refund_id' => $refund->id,
            'component_type' => 'external',
            'amount' => 1000,
            'status' => 'pending_provider',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $order, $refund];
    }
}

class FakeWechatRefundGateway implements WechatPaymentGateway
{
    public string $refundState = 'PROCESSING';

    public function prepare(Payment $payment, User $user): array
    {
        return [];
    }

    public function parseNotification(array $headers, string $body): array
    {
        return [];
    }

    public function query(Payment $payment): array
    {
        return [];
    }

    public function close(Payment $payment): void {}

    public function refund(Payment $payment, Refund $refund): array
    {
        return $this->result($refund, 'PROCESSING', 'submit');
    }

    public function queryRefund(Refund $refund): array
    {
        return $this->result($refund, $this->refundState, 'query');
    }

    public function parseRefundNotification(array $headers, string $body): array
    {
        return (array) json_decode($body, true);
    }

    private function result(Refund $refund, string $state, string $source): array
    {
        return [
            'notification_id' => "fake-{$source}-{$refund->refund_no}-{$state}",
            'event_type' => 'REFUND.'.strtoupper($source),
            'refund_no' => $refund->refund_no,
            'provider_refund_no' => 'wx-refund-1',
            'refund_state' => $state,
            'refund_amount' => $refund->refund_external_amount,
            'total_amount' => 1000,
            'success_time' => $state === 'SUCCESS' ? now()->toIso8601String() : null,
            'raw_resource' => ['state' => $state],
        ];
    }
}

class FakePayoutProvider implements PayoutProvider
{
    public string $state = 'PROCESSING';

    public function submit(WalletWithdrawal $withdrawal): array
    {
        return $this->result($withdrawal, 'PROCESSING', 'submit');
    }

    public function query(WalletWithdrawal $withdrawal): array
    {
        return $this->result($withdrawal, $this->state, 'query');
    }

    public function parseNotification(array $headers, string $body): array
    {
        return (array) json_decode($body, true);
    }

    public function name(): string
    {
        return 'fake';
    }

    private function result(WalletWithdrawal $withdrawal, string $state, string $source): array
    {
        return [
            'event_id' => "fake-{$source}-{$withdrawal->withdrawal_no}-{$state}",
            'event_type' => 'PAYOUT.'.strtoupper($source),
            'withdrawal_no' => $withdrawal->withdrawal_no,
            'provider_reference' => 'payout-ref-1',
            'state' => $state,
            'amount_cents' => $withdrawal->received_amount_cents,
            'occurred_at' => $state === 'SUCCESS' ? now()->toIso8601String() : null,
            'raw_resource' => ['state' => $state],
        ];
    }
}
