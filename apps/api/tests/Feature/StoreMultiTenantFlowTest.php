<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Store\Models\StoreInventory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreMultiTenantFlowTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->campus = Campus::query()->firstOrFail();
    }

    public function test_application_review_and_mobile_workbench_manage_products_in_isolation(): void
    {
        [$owner, $ownerToken] = $this->loginAndConsent('13800138401');
        $applied = $this->withToken($ownerToken)->postJson('/api/v1/me/stores', [
            'name' => '青禾文创店',
            'contact_phone' => '13800138401',
            'description' => '校园原创文创与生活好物。',
            'address' => '东区生活广场 1 号',
            'business_hours' => '09:00-21:00',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $storeId = $applied->json('data.id');

        $this->withToken($ownerToken)->getJson('/api/v1/me/stores')
            ->assertOk()->assertJsonPath('data.items.0.id', $storeId)->assertJsonPath('data.items.0.status', 'pending');
        $this->withToken($ownerToken)->postJson("/api/v1/me/stores/{$storeId}/manager-session")
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        [$auditor, $auditorToken] = $this->admin('auditor', 'store-auditor');
        $this->withToken($auditorToken)->postJson("/api/v1/admin/stores/{$storeId}/review", ['action' => 'approve'])->assertForbidden();

        [, $operationsToken] = $this->admin('operations_admin', 'store-operations');
        $navigation = $this->withToken($operationsToken)->getJson('/api/v1/admin/navigation')->assertOk()->json('data');
        $this->assertSame('/stores', collect($navigation)->firstWhere('code', 'stores')['path']);
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/review", [
            'action' => 'reject', 'reason' => '门店照片与经营地址不清晰',
        ])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', '门店照片与经营地址不清晰');
        $this->withToken($ownerToken)->putJson("/api/v1/me/stores/{$storeId}", ['description' => '校园原创文创与生活好物，支持校内自提。'])
            ->assertOk()->assertJsonPath('data.description', '校园原创文创与生活好物，支持校内自提。');
        $this->withToken($ownerToken)->postJson("/api/v1/me/stores/{$storeId}/resubmit")
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/review", [
            'action' => 'approve', 'commission_rate_bps' => 800,
        ])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.commission_rate_bps', 800);

        $session = $this->withToken($ownerToken)->postJson("/api/v1/me/stores/{$storeId}/manager-session")->assertCreated();
        $managerToken = $session->json('data.access_token');
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/profile')
            ->assertOk()->assertJsonPath('data.member.role', 'owner')->assertJsonPath('data.store.status', 'active');

        $categoryId = $this->withToken($managerToken)->postJson('/api/v1/store-manager/categories', ['name' => '文创周边'])
            ->assertCreated()->json('data.id');
        $product = $this->withToken($managerToken)->postJson('/api/v1/store-manager/products', [
            'name' => '校园文创帆布袋',
            'subtitle' => '限量发售',
            'description' => '加厚帆布，校内自提。',
            'cover_url' => 'https://cdn.example.com/store/cover.png',
            'gallery_images' => ['https://cdn.example.com/store/cover.png'],
            'category_id' => $categoryId,
            'status' => 'on_sale',
            'skus' => [[
                'name' => '米白标准款', 'specs' => ['颜色' => '米白'], 'cash_amount' => 2990,
                'fulfillment_type' => 'no_logistics', 'stock_quantity' => 12,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'on_sale')->assertJsonPath('data.skus.0.available_quantity', 12);
        $productId = $product->json('data.id');
        $skuId = $product->json('data.skus.0.id');
        $inventoryVersion = $product->json('data.skus.0.inventory_version');

        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/stores')->assertOk()
            ->assertJsonPath('data.items.0.id', $storeId)->assertJsonPath('data.items.0.product_count', 1);
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/store-products/'.$productId)->assertOk()->assertJsonPath('data.name', '校园文创帆布袋');
        $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/stores/{$storeId}/products")->assertOk()->assertJsonCount(1, 'data.items');

        $this->withToken($managerToken)->postJson("/api/v1/store-manager/inventory/{$skuId}/adjust", [
            'action' => 'increase', 'quantity' => 5, 'version' => $inventoryVersion, 'reason' => '门店补货。',
        ])->assertOk()->assertJsonPath('data.on_hand_quantity', 17);

        $this->withToken($managerToken)->postJson("/api/v1/store-manager/products/{$productId}/status", ['action' => 'off_shelf'])
            ->assertOk()->assertJsonPath('data.status', 'off_shelf');
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/store-products/'.$productId)->assertNotFound();

        $this->withToken($operationsToken)->getJson('/api/v1/admin/store-products')
            ->assertOk()->assertJsonPath('data.items.0.store.id', $storeId)->assertJsonPath('data.items.0.sku_count', 1);
        $this->withToken($operationsToken)->postJson("/api/v1/admin/store-products/{$productId}/status", [
            'action' => 'off_shelf', 'reason' => '商品图片与描述不符',
        ])->assertOk()->assertJsonPath('data.admin_off_shelf_reason', '商品图片与描述不符');
        $this->withToken($managerToken)->postJson("/api/v1/store-manager/products/{$productId}/status", ['action' => 'publish'])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/store-products/{$productId}/status", ['action' => 'clear_block'])
            ->assertOk()->assertJsonPath('data.admin_off_shelf_reason', null);
        $this->withToken($managerToken)->postJson("/api/v1/store-manager/products/{$productId}/status", ['action' => 'publish'])
            ->assertOk()->assertJsonPath('data.status', 'on_sale');

        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/status", ['action' => 'suspend', 'reason' => '资质复核'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/profile')
            ->assertForbidden()->assertJsonPath('code', 'STORE_NOT_ACTIVE');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/status", ['action' => 'activate'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/profile')->assertOk();

        [$other, $otherToken] = $this->loginAndConsent('13800138402');
        $this->withToken($otherToken)->postJson("/api/v1/me/stores/{$storeId}/manager-session")->assertUnprocessable();
        $this->withToken($otherToken)->getJson('/api/v1/store-manager/profile')->assertUnauthorized();
        $this->assertNotSame($owner->id, $other->id);
    }

    public function test_store_skus_flow_through_cart_checkout_and_admin_settlement(): void
    {
        [$owner, $ownerToken] = $this->loginAndConsent('13800138403');
        $storeId = $this->withToken($ownerToken)->postJson('/api/v1/me/stores', [
            'name' => '拾光零食铺', 'contact_phone' => '13800138403', 'address' => '西区二食堂旁',
        ])->assertCreated()->json('data.id');
        [, $operationsToken] = $this->admin('operations_admin', 'store-ops-2');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/review", [
            'action' => 'approve', 'commission_rate_bps' => 800,
        ])->assertOk();
        $managerToken = $this->withToken($ownerToken)->postJson("/api/v1/me/stores/{$storeId}/manager-session")->assertCreated()->json('data.access_token');
        $this->withToken($managerToken)->putJson('/api/v1/store-manager/store', ['shipping_fee_amount' => 500])->assertOk();
        $product = $this->withToken($managerToken)->postJson('/api/v1/store-manager/products', [
            'name' => '手作曲奇礼盒', 'status' => 'on_sale',
            'skus' => [['name' => '单盒', 'cash_amount' => 2990, 'fulfillment_type' => 'logistics', 'stock_quantity' => 10]],
        ])->assertCreated();
        $skuId = $product->json('data.skus.0.id');
        $productId = $product->json('data.id');
        $this->assertSame($skuId, $product->json('data.skus.0.id'));

        [$buyer, $buyerToken] = $this->loginAndConsent('13800138404');
        UserAssetAccount::query()->firstOrCreate(['user_id' => $buyer->id])->update(['wallet_available_cents' => 10000]);
        $address = UserAddress::query()->create([
            'user_id' => $buyer->id, 'recipient_name' => '测试同学', 'phone' => '13800138404',
            'province' => '江西省', 'city' => '赣州市', 'district' => '章贡区', 'detail' => '校园东区 1 栋',
            'is_default' => true,
        ]);
        $this->withToken($buyerToken)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.items.0.source', 'store')
            ->assertJsonPath('data.items.0.unit_cash_amount', 2990)
            ->assertJsonPath('data.items.0.store.id', $storeId);
        $this->withToken($buyerToken)->postJson('/api/v1/shipping/quote', ['address_id' => $address->public_id])
            ->assertOk()->assertJsonPath('data.shipping_cash_amount', 500);

        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $skuId, 'quantity' => 2, 'purchase_mode' => 'cash']],
            'group_options' => [[
                'fulfillment_type' => 'logistics', 'address_id' => $address->public_id, 'coupon_id' => null, 'cash_payment_method' => 'wallet',
            ]],
        ];
        $preview = $this->userRequest($buyerToken)->postJson('/api/v1/checkout/preview', $request)
            ->assertOk()->assertJsonPath('data.payable_cash_amount', 6480)->assertJsonPath('data.shipping_amount', 500);
        $request['preview_token'] = $preview->json('data.preview_token');
        $created = $this->userRequest($buyerToken, ['Idempotency-Key' => 'store-checkout-order-0001'])
            ->postJson('/api/v1/orders', $request)->assertCreated();
        $orderId = $created->json('data.orders.0.id');
        $this->assertDatabaseHas('order_items', ['store_name' => '拾光零食铺', 'product_sku_id' => null, 'quantity' => 2]);
        $this->assertDatabaseHas('store_inventory_reservations', ['status' => 'reserved', 'quantity' => 2]);
        $this->assertDatabaseHas('store_inventories', ['on_hand_quantity' => 10, 'reserved_quantity' => 2]);

        $order = Order::query()->where('public_id', $orderId)->firstOrFail();
        $order->update(['status' => 'completed', 'paid_at' => now()]);
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $item->update(['paid_cash_amount' => 5980]);
        StoreInventory::query()->firstOrFail()->update(['on_hand_quantity' => 8, 'reserved_quantity' => 0]);

        [, $financeToken] = $this->admin('finance', 'store-finance');
        $period = ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()];
        $this->withToken($financeToken)->getJson("/api/v1/admin/stores/{$storeId}/settlement-preview?".http_build_query($period))
            ->assertOk()->assertJsonPath('data.gross_amount', 5980)->assertJsonPath('data.commission_amount', 478)->assertJsonPath('data.net_amount', 5502);
        $settlement = $this->withToken($financeToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", [...$period, 'remark' => '首期结算'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.net_amount', 5502);
        $settlementId = $settlement->json('data.id');
        $this->withToken($financeToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", $period)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($financeToken)->postJson("/api/v1/admin/store-settlements/{$settlementId}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->withToken($financeToken)->postJson("/api/v1/admin/store-settlements/{$settlementId}/pay")->assertOk()->assertJsonPath('data.status', 'paid');

        $this->withToken($managerToken)->getJson('/api/v1/store-manager/settlements')
            ->assertOk()->assertJsonPath('data.unbilled.item_count', 0)
            ->assertJsonPath('data.items.0.status', 'paid')->assertJsonPath('data.items.0.net_amount', 5502);
        $this->withToken($managerToken)->getJson("/api/v1/store-manager/orders?page=1")
            ->assertOk()->assertJsonPath('data.items.0.store_gross_amount', 5980);
        $this->withToken($managerToken)->getJson("/api/v1/store-manager/orders/{$orderId}")
            ->assertOk()->assertJsonPath('data.items.0.product_name', '手作曲奇礼盒');
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/overview')
            ->assertOk()->assertJsonPath('data.product_counts.on_sale', 1);

        $this->assertDatabaseHas('store_settlement_items', ['gross_amount' => 5980, 'net_amount' => 5502]);
        $this->assertNotNull($productId);
        $this->assertNotSame($owner->id, $buyer->id);
    }

    private function userRequest(string $token, array $headers = []): static
    {
        return $this->withToken($token)->withHeaders([...$headers, 'X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'store-test-device']);
    }

    private function loginAndConsent(string $phone): array
    {
        $user = User::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'nickname' => '门店用户'.$phone,
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'phone_verified_at' => now(),
            'campus_id' => $this->campus->id,
            'status' => 'active',
        ]);
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'store-device-'.$phone),
            'client_platform' => 'h5-browser',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'store-refresh-'.$phone),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $token = app(AccessTokenService::class)->issue($user, $session)['token'];
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'store-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])->assertCreated();
        }

        return [$user, $token];
    }

    private function admin(string $roleCode, string $account): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
