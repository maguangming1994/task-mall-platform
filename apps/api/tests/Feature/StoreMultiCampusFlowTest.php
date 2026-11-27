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
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMultiCampusFlowTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campusA;

    private Campus $campusB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->campusA = Campus::query()->firstOrFail();
        $this->campusB = Campus::query()->create([
            'code' => 'CAMPUS-B', 'name' => '第二校区', 'catalog_type' => 'manual',
            'status' => 'active', 'isolation_mode' => 'legacy',
        ]);
    }

    public function test_store_opens_in_second_campus_with_independent_inventory_and_platform_only_controls(): void
    {
        [$owner, $ownerToken] = $this->loginAndConsent('13800138501', $this->campusA);
        [$storeId, $skuId, $managerToken] = $this->createActiveStore($ownerToken, '13800138511');

        $this->withToken($managerToken)->getJson('/api/v1/store-manager/profile')
            ->assertOk()->assertJsonCount(1, 'data.store.campuses')
            ->assertJsonPath('data.store.campuses.0.is_primary', true);

        // 平台开通第二校区；校区代理无权配置
        [, $agentToken] = $this->admin('campus_agent', 'store-agent-b', $this->campusB);
        $this->withToken($agentToken)->postJson("/api/v1/admin/stores/{$storeId}/campuses/{$this->campusB->public_id}", ['action' => 'enable'])
            ->assertForbidden()->assertJsonPath('code', 'PLATFORM_ADMIN_REQUIRED');
        [, $operationsToken] = $this->admin('operations_admin', 'store-ops-mc');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/campuses/{$this->campusB->public_id}", ['action' => 'enable'])
            ->assertOk()->assertJsonPath('data.campus_name', '第二校区')->assertJsonPath('data.is_primary', false);

        [$buyerB, $buyerBToken] = $this->loginAndConsent('13800138502', $this->campusB);
        $this->withToken($buyerBToken)->getJson('/api/v1/stores')->assertOk()->assertJsonPath('data.items.0.id', $storeId);
        $this->withToken($buyerBToken)->getJson("/api/v1/stores/{$storeId}")
            ->assertOk()->assertJsonPath('data.products.0.available_quantity', 0);
        // 第二校区尚未补货，不能加购；主校区库存不受影响
        $this->withToken($buyerBToken)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->withToken($managerToken)->postJson("/api/v1/store-manager/inventory/{$skuId}/adjust", [
            'campus_id' => $this->campusB->public_id, 'action' => 'set', 'quantity' => 8, 'version' => 1, 'reason' => '第二校区首次补货',
        ])->assertOk()->assertJsonPath('data.on_hand_quantity', 8);
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/inventory')
            ->assertOk()->assertJsonPath('data.items.0.on_hand_quantity', 10);
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/inventory?campus_id='.$this->campusB->public_id)
            ->assertOk()->assertJsonPath('data.items.0.on_hand_quantity', 8);

        $this->withToken($buyerBToken)->getJson("/api/v1/stores/{$storeId}")
            ->assertOk()->assertJsonPath('data.products.0.available_quantity', 8);
        $this->withToken($buyerBToken)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.items.0.available_quantity', 8);

        // 第二校区下单只预占第二校区库存
        $orderId = $this->checkout($buyerB, $buyerBToken, $skuId, 2, 'store-mc-order-b');
        $this->assertNotEmpty($orderId);
        $inventoryA = StoreInventory::query()->where('campus_id', $this->campusA->id)->firstOrFail();
        $inventoryB = StoreInventory::query()->where('campus_id', $this->campusB->id)->firstOrFail();
        $this->assertSame(10, (int) $inventoryA->on_hand_quantity);
        $this->assertSame(0, (int) $inventoryA->reserved_quantity);
        $this->assertSame(8, (int) $inventoryB->on_hand_quantity);
        $this->assertSame(2, (int) $inventoryB->reserved_quantity);

        // 门店端按校区查看订单
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/orders')->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/orders?campus_id='.$this->campusB->public_id)
            ->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($managerToken)->getJson("/api/v1/store-manager/orders/{$orderId}?campus_id=".$this->campusB->public_id)
            ->assertOk()->assertJsonPath('data.id', $orderId);

        // 校区代理可见门店与概览，但结算等敏感操作仅平台管理员
        $this->withToken($agentToken)->getJson('/api/v1/admin/stores')->assertOk()->assertJsonPath('data.items.0.id', $storeId);
        $period = ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()];
        $this->withToken($agentToken)->getJson("/api/v1/admin/stores/{$storeId}/settlement-preview?".http_build_query($period))
            ->assertForbidden();
        $this->withToken($agentToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", $period)
            ->assertForbidden();

        // 主校区不可停用；停用第二校区后该校区不可见不可买，主校区不受影响
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/campuses/{$this->campusA->public_id}", ['action' => 'disable'])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/campuses/{$this->campusB->public_id}", ['action' => 'disable'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->withToken($buyerBToken)->getJson('/api/v1/stores')->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($buyerBToken)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 1])
            ->assertUnprocessable();
        [, $buyerAToken] = $this->loginAndConsent('13800138503', $this->campusA);
        $this->withToken($buyerAToken)->getJson('/api/v1/stores')->assertOk()->assertJsonPath('data.items.0.id', $storeId);
        $this->withToken($buyerAToken)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 1])
            ->assertCreated();

        $this->assertNotNull($owner);
    }

    public function test_settlements_are_previewed_and_generated_per_campus(): void
    {
        [, $ownerToken] = $this->loginAndConsent('13800138504', $this->campusA);
        [$storeId, $skuId, $managerToken] = $this->createActiveStore($ownerToken, '13800138512');
        [, $operationsToken] = $this->admin('operations_admin', 'store-ops-mc2');
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/campuses/{$this->campusB->public_id}", ['action' => 'enable'])->assertOk();
        $this->withToken($managerToken)->postJson("/api/v1/store-manager/inventory/{$skuId}/adjust", [
            'campus_id' => $this->campusB->public_id, 'action' => 'set', 'quantity' => 8, 'version' => 1,
        ])->assertOk();

        [$buyerA, $buyerAToken] = $this->loginAndConsent('13800138505', $this->campusA);
        [$buyerB, $buyerBToken] = $this->loginAndConsent('13800138506', $this->campusB);
        $orderA = $this->checkout($buyerA, $buyerAToken, $skuId, 2, 'store-mc-order-a2');
        $orderB = $this->checkout($buyerB, $buyerBToken, $skuId, 1, 'store-mc-order-b2');
        $this->markPaid($orderA);
        $this->markPaid($orderB);

        [, $financeToken] = $this->admin('finance', 'store-finance-mc');
        $period = ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()];
        $this->withToken($financeToken)->getJson("/api/v1/admin/stores/{$storeId}/settlement-preview?".http_build_query($period))
            ->assertOk()->assertJsonPath('data.gross_amount', 5980)->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.campus_id', $this->campusA->public_id);
        $this->withToken($financeToken)->getJson("/api/v1/admin/stores/{$storeId}/settlement-preview?".http_build_query([...$period, 'campus_id' => $this->campusB->public_id]))
            ->assertOk()->assertJsonPath('data.gross_amount', 2990)->assertJsonPath('data.campus_name', '第二校区');

        $settlementA = $this->withToken($financeToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", $period)
            ->assertCreated()->assertJsonPath('data.campus_id', $this->campusA->public_id)->assertJsonPath('data.net_amount', 5502);
        $settlementB = $this->withToken($financeToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", [...$period, 'campus_id' => $this->campusB->public_id])
            ->assertCreated()->assertJsonPath('data.campus_name', '第二校区')->assertJsonPath('data.item_count', 1);
        // 同校区同周期不可重复，但另一校区互不影响（上面两单已成功）
        $this->withToken($financeToken)->postJson("/api/v1/admin/stores/{$storeId}/settlements", $period)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        // 门店端按校区查看账单与待结算
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/settlements')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $settlementA->json('data.id'))
            ->assertJsonPath('data.unbilled.item_count', 0);
        $this->withToken($managerToken)->getJson('/api/v1/store-manager/settlements?campus_id='.$this->campusB->public_id)
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $settlementB->json('data.id'));

        // 后台详情按校区返回待结算；校区代理只能看到本校区批次
        $this->withToken($financeToken)->getJson("/api/v1/admin/stores/{$storeId}")
            ->assertOk()->assertJsonCount(2, 'data.campuses');
        [, $agentToken] = $this->admin('campus_agent', 'store-agent-b2', $this->campusB);
        $agentList = $this->withToken($agentToken)->getJson('/api/v1/admin/store-settlements')->assertOk();
        $agentList->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $settlementB->json('data.id'));
    }

    /** @return array{0:string,1:string,2:string} 门店 public_id、SKU public_id、门店令牌 */
    private function createActiveStore(string $ownerToken, string $opsAccount): array
    {
        $storeId = $this->withToken($ownerToken)->postJson('/api/v1/me/stores', [
            'name' => '多校区文创店', 'contact_phone' => '13800138500', 'address' => '东区生活广场 1 号',
        ])->assertCreated()->json('data.id');
        [, $operationsToken] = $this->admin('operations_admin', $opsAccount);
        $this->withToken($operationsToken)->postJson("/api/v1/admin/stores/{$storeId}/review", [
            'action' => 'approve', 'commission_rate_bps' => 800,
        ])->assertOk();
        $managerToken = $this->withToken($ownerToken)->postJson("/api/v1/me/stores/{$storeId}/manager-session")->assertCreated()->json('data.access_token');
        $product = $this->withToken($managerToken)->postJson('/api/v1/store-manager/products', [
            'name' => '校园帆布袋', 'status' => 'on_sale',
            'skus' => [['name' => '米白标准款', 'cash_amount' => 2990, 'fulfillment_type' => 'logistics', 'stock_quantity' => 10]],
        ])->assertCreated()->assertJsonPath('data.skus.0.available_quantity', 10);

        return [$storeId, $product->json('data.skus.0.id'), $managerToken];
    }

    private function checkout(User $buyer, string $token, string $skuId, int $quantity, string $key): string
    {
        UserAssetAccount::query()->firstOrCreate(['user_id' => $buyer->id])->update(['wallet_available_cents' => 100000]);
        $address = UserAddress::query()->create([
            'user_id' => $buyer->id, 'recipient_name' => '测试同学', 'phone' => $buyer->phone,
            'province' => '江西省', 'city' => '赣州市', 'district' => '章贡区', 'detail' => '校园东区 1 栋', 'is_default' => true,
        ]);
        $request = [
            'capability_version' => 1,
            'items' => [['sku_id' => $skuId, 'quantity' => $quantity, 'purchase_mode' => 'cash']],
            'group_options' => [[
                'fulfillment_type' => 'logistics', 'address_id' => $address->public_id, 'coupon_id' => null, 'cash_payment_method' => 'wallet',
            ]],
        ];
        $preview = $this->userRequest($token)->postJson('/api/v1/checkout/preview', $request)->assertOk();
        $request['preview_token'] = $preview->json('data.preview_token');

        return $this->userRequest($token, ['Idempotency-Key' => $key])->postJson('/api/v1/orders', $request)
            ->assertCreated()->json('data.orders.0.id');
    }

    private function markPaid(string $orderId): void
    {
        $order = Order::query()->withoutGlobalScope('campus_context')->where('public_id', $orderId)->firstOrFail();
        $order->update(['status' => 'completed', 'paid_at' => now()]);
        OrderItem::query()->where('order_id', $order->id)->get()->each(function (OrderItem $item): void {
            $item->update(['paid_cash_amount' => (int) $item->unit_cash_amount * (int) $item->quantity]);
        });
    }

    private function userRequest(string $token, array $headers = []): static
    {
        return $this->withToken($token)->withHeaders([...$headers, 'X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'store-mc-device']);
    }

    private function loginAndConsent(string $phone, Campus $campus): array
    {
        $user = User::query()->create([
            'public_id' => (string) Str::ulid(),
            'nickname' => '多校区用户'.$phone,
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'phone_verified_at' => now(),
            'campus_id' => $campus->id,
            'status' => 'active',
        ]);
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'store-mc-device-'.$phone),
            'client_platform' => 'h5-browser',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'store-mc-refresh-'.$phone),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $token = app(AccessTokenService::class)->issue($user, $session)['token'];
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'store-mc-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])->assertCreated();
        }

        return [$user, $token];
    }

    private function admin(string $roleCode, string $account, ?Campus $campus = null): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        if ($campus !== null) {
            $admin->campuses()->attach($campus->id, ['is_primary' => true]);
        }
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
