<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Growth\Models\Referral;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Order\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutOrderCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_preview_splits_by_fulfillment_and_never_mutates_inventory_or_assets(): void
    {
        $admin = $this->adminToken();
        $logistics = $this->product($admin, 'CHECK-L', 'logistics', 10);
        $service = $this->product($admin, 'CHECK-N', 'no_logistics', 10);
        [$user, $token] = $this->loginAndConsent('13800138201');
        app(AssetLedgerService::class)->grantPoints($user, 10000, 'daily_check_in', 'checkout-preview', '测试积分。', 'checkout-preview-points');
        $address = $this->address($token, 'preview-address-001');
        $request = [
            'capability_version' => 1,
            'items' => [
                ['sku_id' => $logistics['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash'],
                ['sku_id' => $service['sku_id'], 'quantity' => 1, 'purchase_mode' => 'points'],
            ],
            'group_options' => [
                ['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wechat'],
                ['fulfillment_type' => 'no_logistics'],
            ],
        ];
        $preview = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)
            ->assertOk()->assertJsonCount(2, 'data.groups')->assertJsonPath('data.items_cash_amount', 1990)
            ->assertJsonPath('data.items_points', 2600)->assertJsonPath('data.shipping_amount', 600)
            ->assertJsonPath('data.payable_cash_amount', 2590)->assertJsonPath('data.payable_points', 2600);
        $this->assertSame(64, strlen($preview->json('data.preview_token')));
        $this->assertDatabaseCount('order_batches', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 10000, 'points_frozen' => 0]);
    }

    public function test_create_is_idempotent_and_each_split_order_uses_the_correct_asset_and_stock_lifecycle(): void
    {
        $admin = $this->adminToken('checkout-create-admin');
        $logistics = $this->product($admin, 'CREATE-L', 'logistics', 10);
        $service = $this->product($admin, 'CREATE-N', 'no_logistics', 10);
        [$user, $token] = $this->loginAndConsent('13800138202');
        app(AssetLedgerService::class)->grantPoints($user, 5000, 'daily_check_in', 'checkout-create', '测试积分。', 'checkout-create-points');
        $address = $this->address($token, 'create-address-0001');
        $request = $this->splitRequest($logistics['sku_id'], $service['sku_id'], $address);
        $previewToken = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()->json('data.preview_token');
        $request['preview_token'] = $previewToken;
        $headers = ['Idempotency-Key' => 'checkout-create-order-0001'];
        $created = $this->userRequest($token, $headers)->postJson('/api/v1/orders', $request)
            ->assertCreated()->assertJsonCount(2, 'data.orders');
        $batchId = $created->json('data.id');
        $this->userRequest($token, $headers)->postJson('/api/v1/orders', $request)->assertCreated()->assertJsonPath('data.id', $batchId);

        $orders = collect($created->json('data.orders'));
        $cash = $orders->firstWhere('fulfillment_type', 'logistics');
        $points = $orders->firstWhere('fulfillment_type', 'no_logistics');
        $this->assertSame('pending_payment', $cash['status']);
        $this->assertSame('pending_fulfillment', $points['status']);
        $this->assertSame(2600, $points['paid_points']);
        $this->assertDatabaseCount('order_batches', 1);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('inventory_reservations', 2);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'reserved']);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'consumed']);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 2400, 'points_frozen' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 9, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 10, 'reserved_quantity' => 1]);
        $this->userRequest($token)->getJson('/api/v1/order-batches/'.$batchId)->assertOk()->assertJsonCount(2, 'data.orders');
        $this->userRequest($token)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_cash_points_order_locks_coupon_points_and_inventory_until_cash_payment(): void
    {
        $adminToken = $this->adminToken('checkout-coupon-admin');
        $product = $this->product($adminToken, 'COUPON-N', 'no_logistics', 8);
        [$user, $token] = $this->loginAndConsent('13800138203');
        app(AssetLedgerService::class)->grantPoints($user, 5000, 'daily_check_in', 'checkout-coupon', '测试积分。', 'checkout-coupon-points');
        $coupon = $this->coupon($user);
        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash_points']],
            'group_options' => [['fulfillment_type' => 'no_logistics', 'coupon_id' => $coupon->public_id, 'cash_payment_method' => 'wechat']],
        ];
        $preview = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()
            ->assertJsonPath('data.cash_discount_amount', 100)->assertJsonPath('data.points_discount', 200)
            ->assertJsonPath('data.payable_cash_amount', 890)->assertJsonPath('data.payable_points', 1000);
        $request['preview_token'] = $preview->json('data.preview_token');
        $this->userRequest($token, ['Idempotency-Key' => 'coupon-order-create-0001'])->postJson('/api/v1/orders', $request)
            ->assertCreated()->assertJsonPath('data.orders.0.status', 'pending_payment');
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'locked']);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 4000, 'points_frozen' => 1000, 'coupon_available' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 8, 'reserved_quantity' => 1]);
        $this->assertDatabaseHas('order_point_reservations', ['points' => 1000, 'status' => 'frozen']);
    }

    public function test_preview_token_is_single_use_bound_to_request_and_repricing_fails_without_partial_reservation(): void
    {
        $admin = $this->adminToken('checkout-token-admin');
        $product = $this->product($admin, 'TOKEN-N', 'no_logistics', 6);
        [, $token] = $this->loginAndConsent('13800138204');
        $request = ['capability_version' => 1, 'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash']], 'group_options' => [['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wechat']]];
        $previewToken = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()->json('data.preview_token');
        $changed = $request;
        $changed['items'][0]['quantity'] = 2;
        $changed['preview_token'] = $previewToken;
        $this->userRequest($token, ['Idempotency-Key' => 'checkout-token-mismatch-1'])->postJson('/api/v1/orders', $changed)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $option = collect($this->withToken($admin)->getJson('/api/v1/admin/products/'.$product['product_id'])->json('data.skus.0.purchase_options'))->firstWhere('purchase_mode', 'cash');
        $this->withToken($admin)->putJson('/api/v1/admin/products/'.$product['product_id'].'/skus/'.$product['sku_id'].'/purchase-options/cash', ['cash_amount' => 2090, 'points' => 0, 'enabled' => true, 'version' => $option['version']])->assertOk();
        $request['preview_token'] = $previewToken;
        $this->userRequest($token, ['Idempotency-Key' => 'checkout-reprice-fail-001'])->postJson('/api/v1/orders', $request)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('order_batches', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 6, 'reserved_quantity' => 0]);
    }

    public function test_logistics_requires_owned_address_and_wallet_must_cover_the_entire_cash_due(): void
    {
        $admin = $this->adminToken('checkout-guard-admin');
        $product = $this->product($admin, 'GUARD-L', 'logistics', 5);
        [$user, $token] = $this->loginAndConsent('13800138205');
        [, $otherToken] = $this->loginAndConsent('13800138206');
        $otherAddress = $this->address($otherToken, 'other-address-00001');
        $base = ['capability_version' => 1, 'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash']]];
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [...$base, 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $otherAddress, 'cash_payment_method' => 'wechat']]])->assertNotFound();
        $address = $this->address($token, 'guard-address-0001');
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [...$base, 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wallet']]])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 2590]);
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [...$base, 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wallet']]])
            ->assertOk()->assertJsonPath('data.payable_cash_amount', 2590)->assertJsonPath('data.groups.0.cash_payment_method', 'wallet');
    }

    public function test_wallet_payment_is_full_atomic_idempotent_and_settles_every_reserved_resource(): void
    {
        $admin = $this->adminToken('wallet-payment-admin');
        $product = $this->product($admin, 'WALLET-PAY', 'no_logistics', 5);
        [$user, $token] = $this->loginAndConsent('13800138211');
        app(AssetLedgerService::class)->grantPoints($user, 3000, 'daily_check_in', 'wallet-pay', '支付测试积分。', 'wallet-pay-points');
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 2000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash_points', 'wallet', 'wallet-order-0001');
        $headers = ['Idempotency-Key' => 'wallet-payment-0001'];
        $payment = $this->userRequest($token, $headers)->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertCreated()->assertJsonPath('data.channel', 'wallet')->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.client_payload.kind', 'wallet');
        $this->userRequest($token, $headers)->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->assertJsonPath('data.id', $payment->json('data.id'));
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'pending_fulfillment', 'paid_wallet_amount' => 990, 'paid_points' => 1200]);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'wallet_available_cents' => 1010, 'points_available' => 1800, 'points_frozen' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 4, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'consumed']);
        $this->assertDatabaseHas('order_point_reservations', ['status' => 'consumed']);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_wallet_balance_change_before_payment_fails_without_partial_settlement(): void
    {
        $admin = $this->adminToken('wallet-race-admin');
        $product = $this->product($admin, 'WALLET-RACE', 'no_logistics', 4);
        [$user, $token] = $this->loginAndConsent('13800138212');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'wallet-race-order');
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 100]);
        $this->userRequest($token, ['Idempotency-Key' => 'wallet-race-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'pending_payment', 'paid_wallet_amount' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 4, 'reserved_quantity' => 1]);
    }

    public function test_wechat_payment_preparation_is_scene_aware_and_does_not_settle_before_callback(): void
    {
        $admin = $this->adminToken('wechat-payment-admin');
        $product = $this->product($admin, 'WECHAT-PAY', 'no_logistics', 3);
        [, $token] = $this->loginAndConsent('13800138213');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wechat', 'wechat-order-0001');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'wechat-payment-0001'])->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertCreated()->assertJsonPath('data.channel', 'wechat')->assertJsonPath('data.scene', 'h5-browser')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.client_payload.kind', 'mweb')
            ->assertJsonPath('data.client_payload.sandbox', true);
        $this->userRequest($token)->getJson('/api/v1/payments/'.$payment->json('data.id'))->assertOk()->assertJsonPath('data.order_id', $order['id']);
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'].'/payments')->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'pending_payment', 'paid_external_amount' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 3, 'reserved_quantity' => 1]);
        $this->assertDatabaseHas('payment_attempts', ['status' => 'prepared']);
    }

    public function test_trusted_wechat_callback_settles_once_and_late_closed_event_cannot_reverse_success(): void
    {
        $admin = $this->adminToken('wechat-callback-admin');
        $product = $this->product($admin, 'WECHAT-CALL', 'no_logistics', 5);
        [$user, $token] = $this->loginAndConsent('13800138214');
        app(AssetLedgerService::class)->grantPoints($user, 3000, 'daily_check_in', 'wechat-callback', '回调测试积分。', 'wechat-callback-points');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash_points', 'wechat', 'wechat-callback-order');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'wechat-callback-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->json('data');
        $success = $this->localWechatNotification('notify-success-001', $payment['payment_no'], 'SUCCESS', $payment['cash_amount']);
        $headers = ['Wechatpay-Signature' => 'local-test-signature'];
        $this->postJson('/api/v1/payment-notifications/wechat', $success, $headers)->assertOk()->assertJsonPath('code', 'SUCCESS');
        $this->postJson('/api/v1/payment-notifications/wechat', $success, $headers)->assertOk();
        $closed = $this->localWechatNotification('notify-closed-late-001', $payment['payment_no'], 'CLOSED', $payment['cash_amount']);
        $this->postJson('/api/v1/payment-notifications/wechat', $closed, $headers)->assertOk();
        $this->assertDatabaseHas('payments', ['public_id' => $payment['id'], 'status' => 'succeeded', 'provider_trade_no' => 'wx-'.$payment['payment_no']]);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'pending_fulfillment', 'paid_external_amount' => 990, 'paid_points' => 1200]);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 1800, 'points_frozen' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 4, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('payment_notifications', 2);
        $this->assertDatabaseCount('order_status_changes', 2);
    }

    public function test_untrusted_or_wrong_amount_notification_never_settles_order(): void
    {
        $admin = $this->adminToken('wechat-reject-admin');
        $product = $this->product($admin, 'WECHAT-REJ', 'no_logistics', 3);
        [, $token] = $this->loginAndConsent('13800138215');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wechat', 'wechat-reject-order');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'wechat-reject-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->json('data');
        $wrong = $this->localWechatNotification('notify-wrong-amount', $payment['payment_no'], 'SUCCESS', $payment['cash_amount'] - 1);
        $this->postJson('/api/v1/payment-notifications/wechat', $wrong)->assertUnauthorized();
        $this->postJson('/api/v1/payment-notifications/wechat', $wrong, ['Wechatpay-Signature' => 'local-test-signature'])->assertUnprocessable();
        $this->assertDatabaseHas('payment_notifications', ['notification_id' => 'notify-wrong-amount', 'status' => 'rejected']);
        $this->assertDatabaseHas('payments', ['public_id' => $payment['id'], 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'pending_payment']);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 3, 'reserved_quantity' => 1]);
    }

    public function test_expired_payment_order_closes_and_releases_all_reserved_resources_idempotently(): void
    {
        $admin = $this->adminToken('payment-timeout-admin');
        $product = $this->product($admin, 'PAY-TIMEOUT', 'no_logistics', 4);
        [$user, $token] = $this->loginAndConsent('13800138216');
        app(AssetLedgerService::class)->grantPoints($user, 3000, 'daily_check_in', 'payment-timeout', '超时测试积分。', 'payment-timeout-points');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash_points', 'wechat', 'payment-timeout-order');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'payment-timeout-pay'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->json('data');
        Order::query()->where('public_id', $order['id'])->update(['expires_at' => now()->subMinute()]);
        $this->artisan('orders:close-expired-payments')->assertExitCode(0);
        $this->artisan('orders:close-expired-payments')->assertExitCode(0);
        $this->assertDatabaseHas('payments', ['public_id' => $payment['id'], 'status' => 'closed']);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 4, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'released']);
        $this->assertDatabaseHas('order_point_reservations', ['status' => 'released']);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 3000, 'points_frozen' => 0]);
    }

    public function test_standard_bill_reconciliation_finds_differences_and_resolution_is_audited(): void
    {
        $admin = $this->adminToken('reconciliation-admin');
        $product = $this->product($admin, 'RECON-PAY', 'no_logistics', 3);
        [, $token] = $this->loginAndConsent('13800138217');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wechat', 'reconciliation-order');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'reconciliation-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->json('data');
        $success = $this->localWechatNotification('notify-reconciliation', $payment['payment_no'], 'SUCCESS', $payment['cash_amount']);
        $this->postJson('/api/v1/payment-notifications/wechat', $success, ['Wechatpay-Signature' => 'local-test-signature'])->assertOk();
        $payload = ['business_date' => now('Asia/Shanghai')->toDateString(), 'rows' => [['payment_no' => $payment['payment_no'], 'provider_trade_no' => 'wx-'.$payment['payment_no'], 'cash_amount' => $payment['cash_amount'], 'state' => 'SUCCESS'], ['payment_no' => 'PCHANNELONLY0000000001', 'provider_trade_no' => 'wx-channel-only', 'cash_amount' => 880, 'state' => 'SUCCESS']]];
        $headers = ['Idempotency-Key' => 'reconciliation-import-001'];
        $run = $this->withToken($admin)->withHeaders($headers)->postJson('/api/v1/admin/reconciliation-runs', $payload)
            ->assertCreated()->assertJsonPath('data.matched_count', 1)->assertJsonPath('data.difference_count', 1)
            ->assertJsonPath('data.status', 'differences_found');
        $runId = $run->json('data.id');
        $entry = collect($run->json('data.entries'))->firstWhere('result', 'missing_local');
        $this->withToken($admin)->withHeaders($headers)->postJson('/api/v1/admin/reconciliation-runs', $payload)->assertCreated()->assertJsonPath('data.id', $runId);
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'reconciliation-resolve-001'])->postJson('/api/v1/admin/reconciliation-entries/'.$entry['id'].'/resolve', ['action' => 'confirmed_channel_truth', 'reason' => '渠道存在而本地缺失，已创建财务异常工单。'])->assertOk()->assertJsonPath('data.resolution_status', 'confirmed_channel_truth');
        $this->assertDatabaseCount('reconciliation_runs', 1);
        $this->assertDatabaseCount('reconciliation_actions', 1);
    }

    public function test_referral_reward_waits_for_completed_actual_cash_order_and_is_granted_once(): void
    {
        [$referrer] = $this->loginAndConsent('13800138218');
        [$referred, $token] = $this->loginAndConsent('13800138219');
        $referrals = app(ReferralService::class);
        $referral = $referrals->bind($referred, $referrals->code($referrer));
        $referral->update(['first_task_completed_at' => now()]);
        $admin = $this->adminToken('referral-order-admin');
        $product = $this->product($admin, 'REF-CASH', 'no_logistics', 2);
        UserAssetAccount::query()->firstOrCreate(['user_id' => $referred->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'referral-cash-order');
        $this->userRequest($token, ['Idempotency-Key' => 'referral-cash-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->artisan('growth:scan-first-cash-orders')->assertExitCode(0);
        $this->assertNull(Referral::query()->findOrFail($referral->id)->rewarded_at);
        Order::query()->where('public_id', $order['id'])->update(['status' => 'completed']);
        $this->artisan('growth:scan-first-cash-orders')->assertExitCode(0);
        $this->artisan('growth:scan-first-cash-orders')->assertExitCode(0);
        $this->assertNotNull(Referral::query()->findOrFail($referral->id)->rewarded_at);
        $this->assertDatabaseHas('point_transactions', ['user_id' => $referrer->id, 'source_code' => 'referral_new_user']);
        $this->assertDatabaseCount('referrals', 1);
    }

    public function test_no_logistics_fulfillment_requires_reason_caps_quantity_and_user_completes_order(): void
    {
        $admin = $this->adminToken('fulfillment-admin');
        $product = $this->product($admin, 'FULFILL-N', 'no_logistics', 3);
        [$user,$token] = $this->loginAndConsent('13800138220');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'fulfillment-order');
        $this->userRequest($token, ['Idempotency-Key' => 'fulfillment-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $itemId = $order['items'][0]['id'];
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'fulfillment-bad'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', ['method' => 'no_logistics', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertUnprocessable();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'fulfillment-good'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', ['method' => 'no_logistics', 'no_logistics_reason' => '数字权益已在账户内交付。', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'fulfillment-over'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', ['method' => 'no_logistics', 'no_logistics_reason' => '重复交付测试。', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'fulfilled']);
        $this->userRequest($token)->postJson('/api/v1/orders/'.$order['id'].'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'completed']);
    }

    public function test_wallet_and_points_refund_is_capped_by_remaining_quantity_and_recredits_new_point_batch(): void
    {
        $admin = $this->adminToken('refund-admin');
        $product = $this->product($admin, 'REFUND-N', 'no_logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138221');
        app(AssetLedgerService::class)->grantPoints($user, 3000, 'daily_check_in', 'refund-base', '退款测试积分。', 'refund-base-points');
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash_points', 'wallet', 'refund-order');
        $this->userRequest($token, ['Idempotency-Key' => 'refund-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $payload = ['order_id' => $order['id'], 'reason_code' => 'buyer_request', 'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]]];
        $refund = $this->userRequest($token, ['Idempotency-Key' => 'refund-request-001'])->postJson('/api/v1/refunds', $payload)->assertCreated()->assertJsonPath('data.refund_wallet_amount', 990)->assertJsonPath('data.refund_points', 1200);
        $this->userRequest($token, ['Idempotency-Key' => 'refund-request-002'])->postJson('/api/v1/refunds', $payload)->assertUnprocessable();
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refund->json('data.id').'/review', ['decision' => 'approve', 'note' => '核验通过，按原余额及积分路径退款。'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'wallet_available_cents' => 3000, 'points_available' => 3000]);
        $this->assertDatabaseHas('point_batches', ['user_id' => $user->id, 'source_code' => 'order_refund', 'issued_points' => 1200]);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'refunded', 'refunded_wallet_amount' => 990, 'refunded_points' => 1200]);
    }

    public function test_logistics_return_uses_reason_snapshot_requires_shipping_proof_and_refunds_after_receipt(): void
    {
        $admin = $this->adminToken('return-admin');
        $product = $this->product($admin, 'RETURN-L', 'logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138222');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $order = $this->payableLogisticsOrder($token, $product['sku_id'], 'wallet', 'return-order');
        $this->userRequest($token, ['Idempotency-Key' => 'return-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'return-fulfillment'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', [
            'method' => 'logistics', 'carrier_code' => 'sf', 'tracking_no' => 'SF100000001',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $refund = $this->userRequest($token, ['Idempotency-Key' => 'return-request-001'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'], 'service_type' => 'return_and_refund', 'reason_code' => 'buyer_request', 'return_method' => 'logistics',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.responsibility_party', 'buyer');
        $refundId = $refund->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/review', ['decision' => 'approve', 'note' => '同意退货。'])->assertOk()->assertJsonPath('data.status', 'awaiting_return');
        $this->userRequest($token, ['Idempotency-Key' => 'return-shipment-bad'])->postJson('/api/v1/refunds/'.$refundId.'/return-shipment', [
            'carrier_code' => 'sf', 'tracking_no' => 'SF200000001', 'paid_shipping_amount' => 100,
        ])->assertUnprocessable();
        $this->userRequest($token, ['Idempotency-Key' => 'return-shipment-good'])->postJson('/api/v1/refunds/'.$refundId.'/return-shipment', [
            'carrier_code' => 'sf', 'tracking_no' => 'SF200000001', 'paid_shipping_amount' => 0,
        ])->assertOk()->assertJsonPath('data.status', 'return_in_transit');
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/receive-return', ['note' => '仓库已验收。'])->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.original_shipping_refunded', false);
        $this->assertDatabaseHas('return_shipments', ['tracking_no' => 'SF200000001', 'status' => 'received', 'compensation_amount' => 0]);
    }

    public function test_exchange_reserves_remaining_quantity_and_closes_after_configured_user_deadline(): void
    {
        $admin = $this->adminToken('exchange-admin');
        $product = $this->product($admin, 'EXCHANGE-L', 'logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138223');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $order = $this->payableLogisticsOrder($token, $product['sku_id'], 'wallet', 'exchange-order');
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-fulfillment'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', [
            'method' => 'logistics', 'carrier_code' => 'sto', 'tracking_no' => 'STO100000001',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $payload = ['order_item_id' => $order['items'][0]['id'], 'quantity' => 1, 'reason_code' => 'buyer_request', 'return_method' => 'logistics'];
        $exchange = $this->userRequest($token, ['Idempotency-Key' => 'exchange-request-001'])->postJson('/api/v1/exchanges', $payload)->assertCreated();
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-request-002'])->postJson('/api/v1/exchanges', $payload)->assertUnprocessable();
        $exchangeId = $exchange->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$exchangeId.'/review', ['decision' => 'approve', 'reason' => '同意同 SKU 换货。'])->assertOk()->assertJsonPath('data.status', 'waiting_return');
        DB::table('exchanges')->where('public_id', $exchangeId)->update(['return_deadline_at' => now()->subMinute()]);
        $this->artisan('after-sales:close-expired')->assertExitCode(0);
        $this->assertDatabaseHas('exchanges', ['public_id' => $exchangeId, 'status' => 'closed']);
    }

    private function localWechatNotification(string $id, string $paymentNo, string $state, int $amount): array
    {
        return ['id' => $id, 'event_type' => 'TRANSACTION.SUCCESS', 'resource' => ['out_trade_no' => $paymentNo, 'transaction_id' => 'wx-'.$paymentNo, 'trade_state' => $state, 'amount' => ['total' => $amount], 'success_time' => now()->toIso8601String()]];
    }

    private function payableOrder(string $token, string $skuId, string $mode, string $method, string $key): array
    {
        $request = ['capability_version' => 1, 'items' => [['sku_id' => $skuId, 'quantity' => 1, 'purchase_mode' => $mode]], 'group_options' => [['fulfillment_type' => 'no_logistics', 'cash_payment_method' => $method]]];
        $request['preview_token'] = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()->json('data.preview_token');

        return $this->userRequest($token, ['Idempotency-Key' => $key])->postJson('/api/v1/orders', $request)->assertCreated()->json('data.orders.0');
    }

    private function payableLogisticsOrder(string $token, string $skuId, string $method, string $key): array
    {
        $address = $this->address($token, $key.'-address');
        $request = ['capability_version' => 1, 'items' => [['sku_id' => $skuId, 'quantity' => 1, 'purchase_mode' => 'cash']], 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => $method]]];
        $request['preview_token'] = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()->json('data.preview_token');

        return $this->userRequest($token, ['Idempotency-Key' => $key])->postJson('/api/v1/orders', $request)->assertCreated()->json('data.orders.0');
    }

    private function splitRequest(string $logisticsSku, string $serviceSku, string $address): array
    {
        return ['capability_version' => 1, 'items' => [['sku_id' => $logisticsSku, 'quantity' => 1, 'purchase_mode' => 'cash'], ['sku_id' => $serviceSku, 'quantity' => 1, 'purchase_mode' => 'points']], 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wechat'], ['fulfillment_type' => 'no_logistics']]];
    }

    private function product(string $token, string $skuCode, string $fulfillment, int $stock): array
    {
        $shipping = $fulfillment === 'logistics' ? $this->shipping($token, $skuCode) : null;
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-product-'.$skuCode])->postJson('/api/v1/admin/products', [
            'name' => '结算商品 '.$skuCode, 'description' => '结算专项商品。',
            'skus' => [['sku_code' => $skuCode, 'name' => '标准规格', 'specs' => ['规格' => '标准'], 'fulfillment_type' => $fulfillment, 'shipping_template_id' => $shipping, 'purchase_options' => [['purchase_mode' => 'cash', 'cash_amount' => 1990, 'points' => 0, 'enabled' => true], ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => 2600, 'enabled' => true], ['purchase_mode' => 'cash_points', 'cash_amount' => 990, 'points' => 1200, 'enabled' => true]]]],
        ])->assertCreated()->json('data');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-publish-'.$skuCode])->postJson('/api/v1/admin/products/'.$created['id'].'/status', ['action' => 'publish', 'reason' => '结算测试上架。'])->assertOk();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-stock-'.$skuCode])->postJson('/api/v1/admin/inventories/'.$created['skus'][0]['id'].'/adjustments', ['action' => 'increase', 'quantity' => $stock, 'version' => 1, 'reason' => '结算测试入库。'])->assertOk();

        return ['product_id' => $created['id'], 'sku_id' => $created['skus'][0]['id']];
    }

    private function shipping(string $token, string $suffix): string
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-shipping-'.$suffix])->postJson('/api/v1/admin/shipping-templates', ['name' => '固定运费', 'charge_type' => 'fixed', 'fixed_amount' => 600, 'regions' => [['region_codes' => ['*'], 'deliverable' => true, 'remote_surcharge_amount' => 0]], 'free_rules' => [], 'enabled' => true])->assertCreated()->json('data.id');
    }

    private function coupon(User $user): UserCoupon
    {
        $admin = AdminUser::query()->where('account', 'checkout-coupon-admin')->firstOrFail();
        $template = CouponTemplate::query()->create(['name' => '结算组合券', 'cash_discount_cents' => 100, 'points_discount' => 200, 'minimum_cash_cents' => 500, 'minimum_points' => 500, 'valid_days' => 30, 'total_quantity' => 10, 'created_by_admin_id' => $admin->id, 'idempotency_key_hash' => hash('sha256', 'checkout-coupon-template')]);

        return app(AssetLedgerService::class)->issueCoupons($user, $template, 1, '结算测试发券。', 'checkout-coupon-issue', $admin)[0];
    }

    private function address(string $token, string $key): string
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/addresses', ['recipient_name' => '张三', 'phone' => '13800138000', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail' => '科技园 1 号', 'is_default' => true])->assertCreated()->json('data.id');
    }

    private function userRequest(string $token, array $headers = [])
    {
        return $this->withToken($token)->withHeaders(['X-Client-Platform' => 'h5-browser', ...$headers]);
    }

    private function adminToken(string $account = 'checkout-admin'): string
    {
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'checkout-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
