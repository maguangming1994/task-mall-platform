<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Models\ShippingTemplateVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCartShippingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shipping_template_is_idempotent_versioned_and_validated(): void
    {
        $token = $this->adminToken();
        $headers = ['Idempotency-Key' => 'shipping-template-create-001'];
        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/shipping-templates', $this->shippingPayload())
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.definition_version', 1);
        $id = $created->json('data.id');
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/shipping-templates', $this->shippingPayload())
            ->assertCreated()->assertJsonPath('data.id', $id);
        $payload = $this->shippingPayload();
        $payload['charge_type'] = 'by_quantity';
        $payload['fixed_amount'] = null;
        $payload['first_unit'] = 1;
        $payload['first_amount'] = 600;
        $payload['additional_unit'] = 1;
        $payload['additional_amount'] = 200;
        $payload['version'] = 1;
        $this->withToken($token)->putJson("/api/v1/admin/shipping-templates/{$id}", $payload)
            ->assertOk()->assertJsonPath('data.definition_version', 2)->assertJsonPath('data.version', 2);
        $this->withToken($token)->putJson("/api/v1/admin/shipping-templates/{$id}", $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('shipping_templates', 1);
        $this->assertDatabaseCount('shipping_template_versions', 2);

        $version = ShippingTemplateVersion::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $version->update(['content' => ['tampered' => true]]);
    }

    public function test_inventory_adjustment_is_idempotent_locked_and_never_invades_reservations(): void
    {
        $token = $this->adminToken('inventory-root');
        $product = $this->createPublishedProduct($token, 'INV-001');
        $sku = $product['skus'][0];
        $headers = ['Idempotency-Key' => 'inventory-increase-0001'];
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/inventories/'.$sku['id'].'/adjustments', [
            'action' => 'increase', 'quantity' => 20, 'version' => 1, 'reason' => '首批商品入库。',
        ])->assertOk()->assertJsonPath('data.available_quantity', 20)->assertJsonPath('data.version', 2)->assertJsonPath('data.low_stock', false);
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/inventories/'.$sku['id'].'/adjustments', [
            'action' => 'increase', 'quantity' => 20, 'version' => 1, 'reason' => '重复请求。',
        ])->assertOk()->assertJsonPath('data.on_hand_quantity', 20);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'inventory-stale-change-01'])->postJson('/api/v1/admin/inventories/'.$sku['id'].'/adjustments', [
            'action' => 'decrease', 'quantity' => 1, 'version' => 1, 'reason' => '旧版本请求。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $inventory = Inventory::query()->firstOrFail();
        $inventory->update(['reserved_quantity' => 8]);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'inventory-reserved-guard-1'])->postJson('/api/v1/admin/inventories/'.$sku['id'].'/adjustments', [
            'action' => 'set', 'quantity' => 7, 'version' => 2, 'reason' => '不能侵占预占库存。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('inventory_transactions', 1);
        $transaction = InventoryTransaction::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $transaction->update(['reason' => 'tampered']);
    }

    public function test_cart_merges_same_mode_keeps_modes_separate_and_never_reserves_stock(): void
    {
        $adminToken = $this->adminToken('cart-admin');
        $product = $this->createPublishedProduct($adminToken, 'CART-001', 12);
        [, $token] = $this->loginAndConsent('13800138101');
        $skuId = $product['skus'][0]['id'];
        $this->withToken($token)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.items.0.quantity', 2);
        $this->withToken($token)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 1])
            ->assertCreated()->assertJsonPath('data.items.0.quantity', 3);
        $this->withToken($token)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'points', 'quantity' => 1])
            ->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.summary.selected_quantity', 4);
        $this->assertDatabaseHas('inventories', ['on_hand_quantity' => 12, 'reserved_quantity' => 0]);

        $cart = $this->withToken($token)->getJson('/api/v1/cart')->assertOk();
        $itemId = $cart->json('data.items.0.id');
        $this->withToken($token)->putJson('/api/v1/cart/items/'.$itemId, ['quantity' => 4, 'selected' => false])
            ->assertOk()->assertJsonPath('data.items.0.selected', false);
        $this->withToken($token)->deleteJson('/api/v1/cart/items/'.$itemId)->assertOk();
        $this->withToken($token)->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_cart_preserves_but_marks_stale_items_invalid(): void
    {
        $adminToken = $this->adminToken('cart-stale-admin');
        $product = $this->createPublishedProduct($adminToken, 'STALE-001', 3);
        [, $token] = $this->loginAndConsent('13800138102');
        $skuId = $product['skus'][0]['id'];
        $this->withToken($token)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash_points', 'quantity' => 2])->assertCreated();
        $this->withToken($adminToken)->withHeaders(['Idempotency-Key' => 'stale-product-off-shelf'])->postJson('/api/v1/admin/products/'.$product['id'].'/status', ['action' => 'off_shelf', 'reason' => '验证购物车失效标记。'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.valid', false)->assertJsonPath('data.items.0.invalid_reason', 'product_off_shelf')->assertJsonCount(1, 'data.items');
    }

    public function test_shipping_quote_uses_address_region_cash_only_and_free_threshold(): void
    {
        $adminToken = $this->adminToken('shipping-quote-admin');
        $product = $this->createPublishedProduct($adminToken, 'SHIP-001', 10, true);
        [, $token] = $this->loginAndConsent('13800138103');
        $addressId = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'shipping-address-create-1'])->postJson('/api/v1/addresses', [
            'recipient_name' => '张三', 'phone' => '13800138000', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail' => '科技园 1 号', 'is_default' => true,
        ])->assertCreated()->json('data.id');
        $skuId = $product['skus'][0]['id'];
        $this->withToken($token)->postJson('/api/v1/cart/items', ['sku_id' => $skuId, 'purchase_mode' => 'cash', 'quantity' => 1])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/shipping/quote', ['address_id' => $addressId])
            ->assertOk()->assertJsonPath('data.shipping_cash_amount', 1100)->assertJsonPath('data.cash_only', true)->assertJsonPath('data.groups.0.remote_surcharge_amount', 500);
        $itemId = $this->withToken($token)->getJson('/api/v1/cart')->json('data.items.0.id');
        $this->withToken($token)->putJson('/api/v1/cart/items/'.$itemId, ['quantity' => 2])->assertOk();
        $this->withToken($token)->postJson('/api/v1/shipping/quote', ['address_id' => $addressId])
            ->assertOk()->assertJsonPath('data.shipping_cash_amount', 0)->assertJsonPath('data.groups.0.free_rule.type', 'cash_threshold');
    }

    private function createPublishedProduct(string $token, string $skuCode, int $stock = 0, bool $freeThreshold = false): array
    {
        $template = $this->shippingTemplate($token, $freeThreshold);
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'product-'.$skuCode.'-create'])->postJson('/api/v1/admin/products', [
            'name' => '测试商品 '.$skuCode, 'description' => '库存、购物车和运费专项商品。',
            'skus' => [[
                'sku_code' => $skuCode, 'name' => '标准规格', 'specs' => ['规格' => '标准'], 'fulfillment_type' => 'logistics', 'shipping_template_id' => $template,
                'limit_per_order' => 5,
                'purchase_options' => [
                    ['purchase_mode' => 'cash', 'cash_amount' => 1990, 'points' => 0, 'enabled' => true],
                    ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => 2600, 'enabled' => true],
                    ['purchase_mode' => 'cash_points', 'cash_amount' => 990, 'points' => 1200, 'enabled' => true],
                ],
            ]],
        ])->assertCreated()->json('data');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'publish-'.$skuCode.'-product'])->postJson('/api/v1/admin/products/'.$created['id'].'/status', ['action' => 'publish', 'reason' => '专项测试上架。'])->assertOk();
        if ($stock > 0) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'stock-'.$skuCode.'-increase'])->postJson('/api/v1/admin/inventories/'.$created['skus'][0]['id'].'/adjustments', ['action' => 'increase', 'quantity' => $stock, 'version' => 1, 'reason' => '专项测试入库。'])->assertOk();
        }

        return $created;
    }

    private function shippingTemplate(string $token, bool $freeThreshold = false): string
    {
        $suffix = $freeThreshold ? 'free' : 'normal';
        $payload = $this->shippingPayload();
        if ($freeThreshold) {
            $payload['free_rules'] = [['type' => 'cash_threshold', 'threshold_amount' => 3000]];
        }

        return $this->withToken($token)->withHeaders(['Idempotency-Key' => 'shipping-'.$suffix.'-template'])->postJson('/api/v1/admin/shipping-templates', $payload)->assertCreated()->json('data.id');
    }

    private function shippingPayload(): array
    {
        return [
            'name' => '全国固定运费', 'charge_type' => 'fixed', 'fixed_amount' => 600,
            'regions' => [
                ['region_codes' => ['广东省'], 'deliverable' => true, 'remote_surcharge_amount' => 500],
                ['region_codes' => ['*'], 'deliverable' => true, 'remote_surcharge_amount' => 0],
            ],
            'free_rules' => [], 'enabled' => true,
        ];
    }

    private function adminToken(string $account = 'inventory-shipping-root'): string
    {
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'cart-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cart-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
