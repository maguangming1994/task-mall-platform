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
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Refund\Models\Refund;
use App\Modules\Refund\Services\RefundService;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingVersion;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'order_pending_payment']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'order_paid']);
        $this->assertDatabaseCount('inventory_reservations', 2);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'reserved']);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'consumed']);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 2400, 'points_frozen' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 9, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 10, 'reserved_quantity' => 1]);
        $this->userRequest($token)->getJson('/api/v1/order-batches/'.$batchId)->assertOk()->assertJsonCount(2, 'data.orders');
        $firstPage = $this->userRequest($token)->getJson('/api/v1/orders?page=1&page_size=1')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.page', 1)->assertJsonPath('data.pagination.page_size', 1)
            ->assertJsonPath('data.pagination.total', 2)->assertJsonPath('data.pagination.last_page', 2);
        $secondPage = $this->userRequest($token)->getJson('/api/v1/orders?page=2&page_size=1')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.page', 2);
        $this->assertNotSame($firstPage->json('data.items.0.id'), $secondPage->json('data.items.0.id'));
        $this->userRequest($token)->getJson('/api/v1/orders?page_size=101')->assertStatus(422);
        $this->userRequest($token)->getJson('/api/v1/orders?page=0')->assertStatus(422);
    }

    public function test_create_rejects_a_different_normalized_payload_for_the_same_idempotency_key(): void
    {
        $admin = $this->adminToken('checkout-fingerprint-admin');
        $product = $this->product($admin, 'FINGERPRINT-N', 'no_logistics', 5);
        [, $token] = $this->loginAndConsent('13800138230');
        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash']],
            'group_options' => [['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wechat']],
        ];
        $request['preview_token'] = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()->json('data.preview_token');
        $this->userRequest($token, ['Idempotency-Key' => 'checkout-fingerprint-001'])->postJson('/api/v1/orders', $request)->assertCreated();

        $request['items'][0]['quantity'] = 2;
        $this->userRequest($token, ['Idempotency-Key' => 'checkout-fingerprint-001'])->postJson('/api/v1/orders', $request)
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('message', '相同幂等键已绑定不同的订单请求，请更换幂等键。');
    }

    public function test_cash_points_order_locks_coupon_points_and_inventory_until_cash_payment(): void
    {
        $adminToken = $this->adminToken('checkout-coupon-admin');
        $product = $this->product($adminToken, 'COUPON-N', 'no_logistics', 8);
        [$user, $token] = $this->loginAndConsent('13800138203');
        app(AssetLedgerService::class)->grantPoints($user, 5000, 'daily_check_in', 'checkout-coupon', '测试积分。', 'checkout-coupon-points');
        $coupon = $this->coupon($user);
        $admin = AdminUser::query()->where('account', 'checkout-coupon-admin')->firstOrFail();
        $highThresholdTemplate = CouponTemplate::query()->create([
            'name' => '高门槛组合券', 'cash_discount_cents' => 300, 'points_discount' => 500,
            'minimum_cash_cents' => 5000, 'minimum_points' => 5000, 'valid_days' => 30,
            'total_quantity' => 10, 'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'checkout-high-threshold-template'),
        ]);
        $unavailableCoupon = app(AssetLedgerService::class)->issueCoupons($user, $highThresholdTemplate, 1, '结算不可用原因测试。', 'checkout-high-threshold-issue', $admin)[0];
        $baseRequest = [
            'capability_version' => 1,
            'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash_points']],
            'group_options' => [['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wechat']],
        ];
        $couponOptions = collect($this->userRequest($token)->postJson('/api/v1/checkout/preview', $baseRequest)
            ->assertOk()->assertJsonCount(2, 'data.groups.0.coupon_options')->json('data.groups.0.coupon_options'));
        $this->assertTrue($couponOptions->firstWhere('id', $coupon->public_id)['eligible']);
        $this->assertSame(100, $couponOptions->firstWhere('id', $coupon->public_id)['estimated_cash_discount']);
        $this->assertSame(200, $couponOptions->firstWhere('id', $coupon->public_id)['estimated_points_discount']);
        $this->assertFalse($couponOptions->firstWhere('id', $unavailableCoupon->public_id)['eligible']);
        $this->assertStringContainsString('未满足', $couponOptions->firstWhere('id', $unavailableCoupon->public_id)['unavailable_reason']);
        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash_points']],
            'group_options' => [['fulfillment_type' => 'no_logistics', 'coupon_id' => $coupon->public_id, 'cash_payment_method' => 'wechat']],
        ];
        $preview = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk()
            ->assertJsonPath('data.cash_discount_amount', 100)->assertJsonPath('data.points_discount', 200)
            ->assertJsonPath('data.groups.0.selected_coupon_id', $coupon->public_id)
            ->assertJsonPath('data.payable_cash_amount', 890)->assertJsonPath('data.payable_points', 1000);
        $request['preview_token'] = $preview->json('data.preview_token');
        $this->userRequest($token, ['Idempotency-Key' => 'coupon-order-create-0001'])->postJson('/api/v1/orders', $request)
            ->assertCreated()->assertJsonPath('data.orders.0.status', 'pending_payment');
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'locked']);
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 4000, 'points_frozen' => 1000, 'coupon_available' => 1]);
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

    public function test_checkout_preview_rejects_wallet_when_the_mall_switch_is_disabled(): void
    {
        $admin = $this->adminToken('wallet-disabled-admin');
        $product = $this->product($admin, 'WALLET-OFF', 'no_logistics', 5);
        [$user, $token] = $this->loginAndConsent('13800138207');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $definition = SystemSettingDefinition::query()->where('setting_key', 'mall.wallet_payment_enabled')->firstOrFail();
        SystemSettingVersion::query()->create([
            'setting_definition_id' => $definition->id,
            'version' => 1,
            'value' => false,
            'source' => 'publish',
            'reason' => '测试关闭商城余额支付。',
            'published_at' => now(),
        ]);

        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $product['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash']],
            'group_options' => [['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wallet']],
        ];
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.cash_payment_method.0', '商城余额支付已由平台关闭，请选择微信支付。');
    }

    public function test_split_checkout_requires_one_cash_payment_method_and_wallet_covers_the_whole_batch(): void
    {
        $admin = $this->adminToken('checkout-batch-method-admin');
        $logistics = $this->product($admin, 'BATCH-METHOD-L', 'logistics', 5);
        $service = $this->product($admin, 'BATCH-METHOD-N', 'no_logistics', 5);
        [$user, $token] = $this->loginAndConsent('13800138208');
        $address = $this->address($token, 'batch-method-address');
        $base = [
            'capability_version' => 1,
            'items' => [
                ['sku_id' => $logistics['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash'],
                ['sku_id' => $service['sku_id'], 'quantity' => 1, 'purchase_mode' => 'cash'],
            ],
        ];

        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [
            ...$base,
            'group_options' => [
                ['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wallet'],
                ['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wechat'],
            ],
        ])->assertUnprocessable()->assertJsonPath('details.errors.cash_payment_method.0', '同一批订单只能统一选择余额全额支付或微信全额支付，不能混合使用。');

        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 3000]);
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [
            ...$base,
            'group_options' => [
                ['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wallet'],
                ['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wallet'],
            ],
        ])->assertUnprocessable()->assertJsonPath('details.errors.cash_payment_method.0', '余额不足，必须覆盖本批全部订单的现金应付金额。');

        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 5000]);
        $this->userRequest($token)->postJson('/api/v1/checkout/preview', [
            ...$base,
            'group_options' => [
                ['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wallet'],
                ['fulfillment_type' => 'no_logistics', 'cash_payment_method' => 'wallet'],
            ],
        ])->assertOk()->assertJsonPath('data.payable_cash_amount', 4580);
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

    public function test_different_idempotency_keys_reuse_the_single_active_payment_for_an_order(): void
    {
        $admin = $this->adminToken('single-active-payment-admin');
        $product = $this->product($admin, 'SINGLE-ACTIVE-PAY', 'no_logistics', 3);
        [, $token] = $this->loginAndConsent('13800138231');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wechat', 'single-active-payment-order');

        $first = $this->userRequest($token, ['Idempotency-Key' => 'single-active-payment-first'])
            ->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertCreated()
            ->json('data');
        $second = $this->userRequest($token, ['Idempotency-Key' => 'single-active-payment-second'])
            ->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_active_payment_from_another_client_scene_is_safely_rejected(): void
    {
        $admin = $this->adminToken('cross-scene-payment-admin');
        $product = $this->product($admin, 'CROSS-SCENE-PAY', 'no_logistics', 3);
        [, $token] = $this->loginAndConsent('13800138232');
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wechat', 'cross-scene-payment-order');

        $this->userRequest($token, ['Idempotency-Key' => 'cross-scene-payment-first'])
            ->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertCreated();
        $this->userRequest($token, [
            'X-Client-Platform' => 'wechat-mini',
            'Idempotency-Key' => 'cross-scene-payment-second',
        ])->postJson('/api/v1/orders/'.$order['id'].'/payments')
            ->assertUnprocessable()
            ->assertJsonPath('details.errors.payment.0', '该订单已有其他终端发起的支付，请返回原终端继续支付或等待结果。');

        $this->assertDatabaseCount('payments', 1);
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
        [$referrer, $referrerToken] = $this->loginAndConsent('13800138218');
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
        $dashboard = $this->userRequest($referrerToken)->getJson('/api/v1/growth/referrals')
            ->assertOk()
            ->assertJsonPath('data.referral_code', $referrals->code($referrer))
            ->assertJsonPath('data.rules.match_mode', 'all')
            ->assertJsonPath('data.rules.reward.asset_type', 'points')
            ->assertJsonPath('data.rules.reward.points', 500)
            ->assertJsonPath('data.rules.monthly_cap', 5)
            ->assertJsonPath('data.summary.invited_count', 1)
            ->assertJsonPath('data.summary.rewarded_count', 1)
            ->assertJsonPath('data.summary.reward_points_total', 500)
            ->assertJsonPath('data.summary.rewarded_this_month', 1)
            ->assertJsonPath('data.summary.remaining_rewards_this_month', 4)
            ->assertJsonPath('data.items.0.reward.points', 500)
            ->assertJsonPath('data.items.0.reward.available_points', 500)
            ->assertJsonPath('data.items.0.reward.debt_repaid_points', 0)
            ->assertJsonPath('data.reward_records.0.referral_id', $referral->public_id)
            ->assertJsonPath('data.pagination.total', 1);
        $this->assertStringContainsString($referrals->code($referrer), $dashboard->json('data.share.path'));
        $this->assertSame(
            ['first_cash_order', 'first_task'],
            collect($dashboard->json('data.rules.milestones'))->pluck('code')->all(),
        );
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
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'order_fulfilled']);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $user->id)->where('scene_code', 'order_fulfillment_updated')->count());
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

    public function test_refund_idempotency_key_rejects_a_different_normalized_payload(): void
    {
        $admin = $this->adminToken('refund-idempotency-admin');
        $product = $this->product($admin, 'REFUND-IDEMPOTENCY', 'no_logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138235');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'refund-idempotency-order');
        $this->userRequest($token, ['Idempotency-Key' => 'refund-idempotency-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $payload = [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ];

        $first = $this->userRequest($token, ['Idempotency-Key' => 'refund-idempotency-key'])->postJson('/api/v1/refunds', $payload)->assertCreated();
        $this->userRequest($token, ['Idempotency-Key' => 'refund-idempotency-key'])->postJson('/api/v1/refunds', [
            ...$payload,
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 2]],
        ])->assertStatus(409)->assertJsonPath('code', 'REFUND_IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('refunds', ['public_id' => $first->json('data.id'), 'status' => 'submitted']);
    }

    public function test_admin_reject_is_idempotent_but_cannot_rollback_a_later_refund_state(): void
    {
        $admin = $this->adminToken('refund-state-admin');
        $product = $this->product($admin, 'REFUND-STATE', 'no_logistics', 1);
        [$user, $token] = $this->loginAndConsent('13800138236');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'refund-state-order');
        $this->userRequest($token, ['Idempotency-Key' => 'refund-state-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $refund = $this->userRequest($token, ['Idempotency-Key' => 'refund-state-create'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $refundId = $refund->json('data.id');

        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/review', [
            'decision' => 'reject', 'note' => '凭证不足，暂不支持退款。',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/review', [
            'decision' => 'reject', 'note' => '重复提交的不同备注。',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('refunds', ['public_id' => $refundId, 'status' => 'rejected', 'review_note' => '凭证不足，暂不支持退款。']);
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/review', [
            'decision' => 'approve', 'note' => '不应改变已驳回状态。',
        ])->assertUnprocessable();
    }

    public function test_refund_items_reject_duplicate_order_item_ids_clearly(): void
    {
        $admin = $this->adminToken('refund-distinct-admin');
        $product = $this->product($admin, 'REFUND-DISTINCT', 'no_logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138237');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 3000]);
        $order = $this->payableOrder($token, $product['sku_id'], 'cash', 'wallet', 'refund-distinct-order');
        $this->userRequest($token, ['Idempotency-Key' => 'refund-distinct-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $response = $this->userRequest($token, ['Idempotency-Key' => 'refund-distinct-key'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [
                ['order_item_id' => $order['items'][0]['id'], 'quantity' => 1],
                ['order_item_id' => $order['items'][0]['id'], 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertArrayHasKey('items.1.order_item_id', $response->json('details.errors'));
    }

    public function test_coupon_is_returned_once_when_cumulative_refunds_become_full_but_not_for_default_partial_refund(): void
    {
        $admin = $this->adminToken('checkout-coupon-admin');
        $product = $this->product($admin, 'REFUND-COUPON-W', 'no_logistics', 4);
        [$user, $token] = $this->loginAndConsent('13800138231');
        app(AssetLedgerService::class)->grantPoints($user, 5000, 'daily_check_in', 'refund-coupon-wallet', '优惠券退款测试积分。', 'refund-coupon-wallet-points');
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 5000]);
        $coupon = $this->coupon($user);
        $order = $this->couponOrder($token, $product['sku_id'], $coupon, 'wallet', 'refund-coupon-wallet-order', 3);
        $this->userRequest($token, ['Idempotency-Key' => 'refund-coupon-wallet-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'used']);
        $initialItem = $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.refunded_quantity', 0)
            ->assertJsonPath('data.items.0.pending_refund_quantity', 0)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 3)
            ->assertJsonPath('data.items.0.after_sale_workflow.allowed', true)
            ->assertJsonPath('data.items.0.after_sale_workflow.available_service_types.0', 'refund_only')
            ->assertJsonMissingPath('data.items.0.after_sale_workflow.available_service_types.1')
            ->assertJsonPath('data.items.0.after_sale_workflow.return_method', 'no_logistics')
            ->assertJsonPath('data.items.0.after_sale_workflow.requires_return_logistics', false)
            ->json('data.items.0');
        $this->assertSame(intdiv($initialItem['paid_cash_amount'], 3), $initialItem['unit_paid_cash_amount']);
        $this->assertSame(intdiv($initialItem['paid_points'], 3), $initialItem['unit_paid_points']);
        $this->assertSame($initialItem['paid_cash_amount'], $initialItem['refundable_cash_amount']);
        $this->assertSame($initialItem['paid_points'], $initialItem['refundable_points']);

        $first = $this->userRequest($token, ['Idempotency-Key' => 'refund-coupon-partial-001'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame(intdiv($initialItem['paid_cash_amount'] * 2, 3), $first->json('data.refund_cash_amount'));
        $this->assertSame(intdiv($initialItem['paid_points'] * 2, 3), $first->json('data.refund_points'));
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.pending_refund_quantity', 2)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 1);
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$first->json('data.id').'/review', [
            'decision' => 'approve', 'note' => '先完成部分退款。',
        ])->assertOk();
        $partialRefundBody = (string) DB::table('notifications')->where('user_id', $user->id)->where('scene_code', 'order_partially_refunded')->value('body');
        $this->assertStringContainsString(number_format($first->json('data.refund_cash_amount') / 100, 2, '.', ''), $partialRefundBody);
        $this->assertStringContainsString('积分 '.$first->json('data.refund_points'), $partialRefundBody);
        $this->assertDatabaseHas('refunds', ['public_id' => $first->json('data.id'), 'coupon_returned' => false]);
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'used']);
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'partially_refunded']);
        $remainingItem = $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.refunded_quantity', 2)
            ->assertJsonPath('data.items.0.pending_refund_quantity', 0)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 1)
            ->json('data.items.0');
        $this->assertSame($initialItem['paid_cash_amount'] - $first->json('data.refund_cash_amount'), $remainingItem['refundable_cash_amount']);
        $this->assertSame($initialItem['paid_points'] - $first->json('data.refund_points'), $remainingItem['refundable_points']);

        UserCoupon::query()->whereKey($coupon->id)->update(['valid_to' => now()->subDay()]);
        $second = $this->userRequest($token, ['Idempotency-Key' => 'refund-coupon-full-002'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$second->json('data.id').'/review', [
            'decision' => 'approve', 'note' => '累计已达到整单全额退款。',
        ])->assertOk();
        $this->assertDatabaseHas('refunds', ['public_id' => $second->json('data.id'), 'coupon_returned' => true]);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $user->id)->where('scene_code', 'order_partially_refunded')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $user->id)->where('scene_code', 'order_refunded')->count());

        $returned = UserCoupon::query()->findOrFail($coupon->id);
        $this->assertSame('unused', $returned->status);
        $this->assertNull($returned->used_at);
        $this->assertTrue($returned->valid_from->isToday());
        $this->assertTrue($returned->valid_to->isFuture());
        $this->assertTrue($returned->valid_to->lessThanOrEqualTo(now()->addDays(8)));
        $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'coupon_available' => 1]);
        $this->assertDatabaseHas('coupon_transactions', [
            'user_coupon_id' => $coupon->id,
            'transaction_type' => 'return',
            'status_before' => 'used',
            'status_after' => 'unused',
            'business_type' => 'refund',
            'business_id' => $second->json('data.id'),
        ]);
        $this->assertSame(1, DB::table('coupon_transactions')->where('user_coupon_id', $coupon->id)->where('transaction_type', 'return')->count());
        $this->assertDatabaseHas('orders', ['public_id' => $order['id'], 'status' => 'refunded']);
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 0)
            ->assertJsonPath('data.items.0.refundable_cash_amount', 0)
            ->assertJsonPath('data.items.0.refundable_points', 0);
    }

    public function test_external_refund_returns_coupon_only_after_provider_completion_and_completion_is_idempotent(): void
    {
        $admin = $this->adminToken('checkout-coupon-admin');
        $product = $this->product($admin, 'REFUND-COUPON-X', 'no_logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138232');
        app(AssetLedgerService::class)->grantPoints($user, 3000, 'daily_check_in', 'refund-coupon-external', '外部退款测试积分。', 'refund-coupon-external-points');
        $coupon = $this->coupon($user);
        $order = $this->couponOrder($token, $product['sku_id'], $coupon, 'wechat', 'refund-coupon-external-order');
        $payment = $this->userRequest($token, ['Idempotency-Key' => 'refund-coupon-external-payment'])
            ->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated()->json('data');
        $success = $this->localWechatNotification('notify-refund-coupon-external', $payment['payment_no'], 'SUCCESS', $payment['cash_amount']);
        $this->postJson('/api/v1/payment-notifications/wechat', $success, ['Wechatpay-Signature' => 'local-test-signature'])->assertOk();

        $created = $this->userRequest($token, ['Idempotency-Key' => 'refund-coupon-external-request'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'],
            'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $refundId = $created->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$refundId.'/review', [
            'decision' => 'approve', 'note' => '提交微信退款，等待渠道最终结果。',
        ])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseHas('refunds', ['public_id' => $refundId, 'coupon_returned' => false]);
        $this->assertDatabaseHas('refund_components', ['status' => 'pending_provider']);
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'used']);

        $refund = Refund::query()->where('public_id', $refundId)->firstOrFail();
        $service = app(RefundService::class);
        $completed = $service->completeProviderRefund($refund, 'wx-refund-coupon-001');
        $again = $service->completeProviderRefund($refund, 'wx-refund-coupon-001');
        $this->assertSame('succeeded', $completed->status);
        $this->assertSame($completed->id, $again->id);
        $this->assertTrue($completed->coupon_returned);
        $this->assertDatabaseHas('refund_components', [
            'refund_id' => $refund->id,
            'status' => 'succeeded',
            'provider_refund_no' => 'wx-refund-coupon-001',
        ]);
        $this->assertDatabaseHas('orders', [
            'public_id' => $order['id'],
            'status' => 'refunded',
            'refunded_external_amount' => 890,
            'refunded_points' => 1000,
        ]);
        $this->assertDatabaseHas('user_coupons', ['id' => $coupon->id, 'status' => 'unused']);
        $this->assertSame(1, DB::table('coupon_transactions')->where('user_coupon_id', $coupon->id)->where('transaction_type', 'return')->count());
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
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.after_sale_workflow.available_service_types', ['refund_only', 'return_and_refund', 'exchange'])
            ->assertJsonPath('data.items.0.after_sale_workflow.return_method', 'logistics')
            ->assertJsonPath('data.items.0.after_sale_workflow.requires_return_logistics', true);
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
        $changedPayload = $payload;
        $changedPayload['quantity'] = 2;
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-request-001'])->postJson('/api/v1/exchanges', $changedPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-request-002'])->postJson('/api/v1/exchanges', $payload)->assertUnprocessable();
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.active_exchange_quantity', 1)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 0)
            ->assertJsonPath('data.items.0.remaining_exchangeable_quantity', 0);
        $this->userRequest($token, ['Idempotency-Key' => 'refund-while-exchange-active'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'], 'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertUnprocessable();
        $exchangeId = $exchange->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$exchangeId.'/review', ['decision' => 'approve', 'reason' => '同意同 SKU 换货。'])->assertOk()->assertJsonPath('data.status', 'waiting_return');
        DB::table('exchanges')->where('public_id', $exchangeId)->update(['return_deadline_at' => now()->subMinute()]);
        $this->artisan('after-sales:close-expired')->assertExitCode(0);
        $this->assertDatabaseHas('exchanges', ['public_id' => $exchangeId, 'status' => 'closed']);
    }

    public function test_user_can_cancel_exchange_before_and_after_approval_which_releases_reserved_quantity(): void
    {
        $admin = $this->adminToken('exchange-cancel-admin');
        $product = $this->product($admin, 'EXCHANGE-CANCEL', 'logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138225');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $order = $this->payableLogisticsOrder($token, $product['sku_id'], 'wallet', 'exchange-cancel-order');
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-cancel-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-cancel-fulfillment'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', [
            'method' => 'logistics', 'carrier_code' => 'sto', 'tracking_no' => 'STO200000001',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $payload = ['order_item_id' => $order['items'][0]['id'], 'quantity' => 1, 'reason_code' => 'buyer_request', 'return_method' => 'logistics'];

        $first = $this->userRequest($token, ['Idempotency-Key' => 'exchange-cancel-request-1'])->postJson('/api/v1/exchanges', $payload)->assertCreated();
        $firstId = $first->json('data.id');
        $this->userRequest($token)->postJson('/api/v1/exchanges/'.$firstId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.active_exchange_quantity', 0)
            ->assertJsonPath('data.items.0.remaining_exchangeable_quantity', 1);

        $second = $this->userRequest($token, ['Idempotency-Key' => 'exchange-cancel-request-2'])->postJson('/api/v1/exchanges', $payload)->assertCreated();
        $secondId = $second->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$secondId.'/review', ['decision' => 'approve', 'reason' => '同意换货。'])->assertOk()->assertJsonPath('data.status', 'waiting_return');
        $this->userRequest($token)->postJson('/api/v1/exchanges/'.$secondId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

        $third = $this->userRequest($token, ['Idempotency-Key' => 'exchange-cancel-request-3'])->postJson('/api/v1/exchanges', $payload)->assertCreated();
        $thirdId = $third->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$thirdId.'/review', ['decision' => 'approve', 'reason' => '同意换货。'])->assertOk();
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-cancel-return'])->postJson('/api/v1/exchanges/'.$thirdId.'/return-shipment', [
            'carrier_code' => 'sto', 'tracking_no' => 'STO200000099', 'paid_shipping_amount' => 0,
        ])->assertOk()->assertJsonPath('data.status', 'return_in_transit');
        $this->userRequest($token)->postJson('/api/v1/exchanges/'.$thirdId.'/cancel')->assertUnprocessable();
    }

    public function test_user_can_cancel_pending_refund_which_releases_quantity_but_not_after_settlement(): void
    {
        $admin = $this->adminToken('refund-cancel-admin');
        $product = $this->product($admin, 'REFUND-CANCEL', 'logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138226');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $order = $this->payableLogisticsOrder($token, $product['sku_id'], 'wallet', 'refund-cancel-order');
        $this->userRequest($token, ['Idempotency-Key' => 'refund-cancel-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();

        $refund = $this->userRequest($token, ['Idempotency-Key' => 'refund-cancel-request-1'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'], 'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $refundId = $refund->json('data.id');
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.pending_refund_quantity', 1)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 0);
        $this->userRequest($token)->postJson('/api/v1/refunds/'.$refundId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.pending_refund_quantity', 0)
            ->assertJsonPath('data.items.0.remaining_refundable_quantity', 1);
        $this->userRequest($token)->postJson('/api/v1/refunds/'.$refundId.'/cancel')->assertUnprocessable();

        $second = $this->userRequest($token, ['Idempotency-Key' => 'refund-cancel-request-2'])->postJson('/api/v1/refunds', [
            'order_id' => $order['id'], 'reason_code' => 'buyer_request',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $secondId = $second->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/refunds/'.$secondId.'/review', ['decision' => 'approve', 'note' => '钱包退款立即到账。'])->assertOk();
        $this->userRequest($token)->postJson('/api/v1/refunds/'.$secondId.'/cancel')->assertUnprocessable();
    }

    public function test_exchange_replacement_and_return_shipping_compensation_close_with_wallet_ledger(): void
    {
        Storage::fake('local');
        $admin = $this->adminToken('exchange-compensation-admin');
        $product = $this->product($admin, 'EXCHANGE-COMP', 'logistics', 2);
        [$user, $token] = $this->loginAndConsent('13800138224');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->update(['wallet_available_cents' => 5000]);
        $order = $this->payableLogisticsOrder($token, $product['sku_id'], 'wallet', 'exchange-compensation-order');
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-compensation-payment'])->postJson('/api/v1/orders/'.$order['id'].'/payments')->assertCreated();
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-compensation-fulfillment'])->postJson('/api/v1/admin/orders/'.$order['id'].'/fulfillments', [
            'method' => 'logistics', 'carrier_code' => 'sf', 'carrier_name' => '顺丰速运', 'tracking_no' => 'SF300000001',
            'items' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated();
        $this->userRequest($token)->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()->assertJsonPath('data.fulfillments.0.carrier_name', '顺丰速运')->assertJsonPath('data.fulfillments.0.tracking_no', 'SF300000001');

        $evidence = $this->cleanFile($user->id, 'refund_evidence', 'exchange-evidence');
        $proof = $this->cleanFile($user->id, 'return_shipping', 'exchange-shipping-proof');
        Storage::disk('local')->put($proof->quarantine_path, 'proof-content');
        $exchange = $this->userRequest($token, ['Idempotency-Key' => 'exchange-compensation-request'])->postJson('/api/v1/exchanges', [
            'order_item_id' => $order['items'][0]['id'], 'quantity' => 1, 'reason_code' => 'quality_problem', 'return_method' => 'logistics',
            'evidence_file_ids' => [$evidence->public_id],
        ])->assertCreated();
        $exchangeId = $exchange->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$exchangeId.'/review', [
            'decision' => 'approve', 'reason' => '质量问题，卖家承担寄回与补发运费。', 'responsibility_party' => 'seller',
            'return_shipping_payer' => 'seller', 'replacement_shipping_payer' => 'seller', 'return_method' => 'logistics',
        ])->assertOk()->assertJsonPath('data.status', 'waiting_return');
        $this->userRequest($token, ['Idempotency-Key' => 'exchange-compensation-return'])->postJson('/api/v1/exchanges/'.$exchangeId.'/return-shipment', [
            'carrier_code' => 'zto', 'tracking_no' => 'ZTO300000001', 'paid_shipping_amount' => 500, 'payment_proof_file_id' => $proof->public_id,
        ])->assertOk()->assertJsonPath('data.status', 'return_in_transit')->assertJsonPath('data.shipments.0.compensation.status', 'submitted');

        $compensationId = $this->withToken($admin)->getJson('/api/v1/admin/return-shipping-compensations')->assertOk()->json('data.items.0.id');
        $this->withToken($admin)->get('/api/v1/admin/return-shipping-compensations/'.$compensationId.'/proof')
            ->assertOk()->assertHeader('content-type', 'image/png')->assertDownload($proof->original_name);
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-compensation-review'])->postJson('/api/v1/admin/return-shipping-compensations/'.$compensationId.'/review', [
            'decision' => 'approve', 'approved_amount' => 500, 'note' => '物流单号与付款凭证核验通过。',
        ])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.approved_amount', 500);
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-compensation-review'])->postJson('/api/v1/admin/return-shipping-compensations/'.$compensationId.'/review', [
            'decision' => 'approve', 'approved_amount' => 500, 'note' => '重复请求不会重复入账。',
        ])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.approved_amount', 500);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'transaction_type' => 'shipping_compensation', 'change_available_cents' => 500]);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $user->id)->where('transaction_type', 'shipping_compensation')->count());

        $this->withToken($admin)->postJson('/api/v1/admin/exchanges/'.$exchangeId.'/receive-return', [
            'note' => '仓库已验收寄回商品。',
        ])->assertOk()->assertJsonPath('data.status', 'replacement_pending')->assertJsonFragment(['direction' => 'return', 'status' => 'received']);
        $this->withToken($admin)->withHeaders(['Idempotency-Key' => 'exchange-replacement-fulfillment'])->postJson('/api/v1/admin/exchanges/'.$exchangeId.'/replacement-fulfillment', [
            'fulfillment_type' => 'logistics', 'carrier_code' => 'sf', 'tracking_no' => 'SF400000001',
        ])->assertOk()->assertJsonPath('data.status', 'replacement_shipped')->assertJsonFragment(['direction' => 'replacement', 'tracking_no' => 'SF400000001']);
        $this->userRequest($token)->postJson('/api/v1/exchanges/'.$exchangeId.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('exchange_shipments', ['exchange_id' => DB::table('exchanges')->where('public_id', $exchangeId)->value('id'), 'direction' => 'replacement', 'status' => 'received']);
    }

    private function localWechatNotification(string $id, string $paymentNo, string $state, int $amount): array
    {
        return ['id' => $id, 'event_type' => 'TRANSACTION.SUCCESS', 'resource' => ['out_trade_no' => $paymentNo, 'transaction_id' => 'wx-'.$paymentNo, 'trade_state' => $state, 'amount' => ['total' => $amount], 'success_time' => now()->toIso8601String()]];
    }

    private function cleanFile(int $userId, string $purpose, string $key): UploadedFile
    {
        return UploadedFile::query()->create([
            'owner_user_id' => $userId, 'purpose' => $purpose, 'original_name' => $key.'.png', 'extension' => 'png',
            'declared_mime' => 'image/png', 'detected_mime' => 'image/png', 'declared_size' => 8, 'actual_size' => 8,
            'sha256' => hash('sha256', $key), 'disk' => 'local', 'quarantine_path' => 'tests/'.$key.'.png', 'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', $key), 'ticket_expires_at' => now()->addHour(), 'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
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

    private function couponOrder(
        string $token,
        string $skuId,
        UserCoupon $coupon,
        string $method,
        string $key,
        int $quantity = 1,
    ): array {
        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $skuId, 'quantity' => $quantity, 'purchase_mode' => 'cash_points']],
            'group_options' => [[
                'fulfillment_type' => 'no_logistics',
                'coupon_id' => $coupon->public_id,
                'cash_payment_method' => $method,
            ]],
        ];
        $request['preview_token'] = $this->userRequest($token)
            ->postJson('/api/v1/checkout/preview', $request)
            ->assertOk()
            ->json('data.preview_token');

        return $this->userRequest($token, ['Idempotency-Key' => $key])
            ->postJson('/api/v1/orders', $request)
            ->assertCreated()
            ->json('data.orders.0');
    }

    private function splitRequest(string $logisticsSku, string $serviceSku, string $address): array
    {
        return ['capability_version' => 1, 'items' => [['sku_id' => $logisticsSku, 'quantity' => 1, 'purchase_mode' => 'cash'], ['sku_id' => $serviceSku, 'quantity' => 1, 'purchase_mode' => 'points']], 'group_options' => [['fulfillment_type' => 'logistics', 'address_id' => $address, 'cash_payment_method' => 'wechat'], ['fulfillment_type' => 'no_logistics']]];
    }

    private function product(string $token, string $skuCode, string $fulfillment, int $stock): array
    {
        $shipping = $fulfillment === 'logistics' ? $this->shipping($token, $skuCode) : null;
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'checkout-tests'],
            ['name' => '结算测试分类', 'status' => 'active', 'sort_order' => 1],
        );
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'checkout-product-'.$skuCode])->postJson('/api/v1/admin/products', [
            'category_id' => $category->public_id, 'name' => '结算商品 '.$skuCode, 'description' => '结算专项商品。',
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
