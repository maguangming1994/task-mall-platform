<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Product\Models\ProductVersion;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_idempotent_spu_sku_with_all_three_purchase_modes(): void
    {
        $token = $this->adminToken();
        $category = $this->category($token);
        $headers = ['Idempotency-Key' => 'product-create-three-modes-001'];
        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/products', $this->product($category))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(1, 'data.skus')->assertJsonCount(3, 'data.skus.0.purchase_options');
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/products', $this->product($category))
            ->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_skus', 1);
        $this->assertDatabaseCount('sku_purchase_options', 3);
        $this->assertDatabaseCount('product_versions', 1);
        $this->assertDatabaseHas('sku_purchase_options', ['purchase_mode' => 'cash', 'cash_amount' => 1990, 'points' => 0]);
        $this->assertDatabaseHas('sku_purchase_options', ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => 2600]);
        $this->assertDatabaseHas('sku_purchase_options', ['purchase_mode' => 'cash_points', 'cash_amount' => 990, 'points' => 1200]);
    }

    public function test_price_rules_and_unique_modes_are_fail_closed(): void
    {
        $token = $this->adminToken('product-invalid-root');
        $category = $this->category($token, 'invalid-goods');
        $payload = $this->product($category, 'INVALID-001');
        $payload['skus'][0]['purchase_options'][0]['points'] = 1;
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'invalid-product-price-001'])->postJson('/api/v1/admin/products', $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('products', 0);

        $payload = $this->product($category, 'INVALID-002');
        $payload['skus'][0]['purchase_options'][1]['purchase_mode'] = 'cash';
        $payload['skus'][0]['purchase_options'][1]['cash_amount'] = 100;
        $payload['skus'][0]['purchase_options'][1]['points'] = 0;
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'duplicate-product-mode-01'])->postJson('/api/v1/admin/products', $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_only_on_sale_products_are_public_and_every_client_sees_identical_prices(): void
    {
        $token = $this->adminToken('product-publish-root');
        $category = $this->category($token, 'public-goods');
        $shippingTemplate = $this->shippingTemplate($token);
        $created = $this->createProduct($token, $category, 'PUBLIC-001', 'public-product-create-01', $shippingTemplate);
        $id = $created['id'];
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data.items');

        $headers = ['Idempotency-Key' => 'product-publish-action-001'];
        $this->withToken($token)->withHeaders($headers)->postJson("/api/v1/admin/products/{$id}/status", ['action' => 'publish', 'reason' => '商品资料和三种价格已复核。'])
            ->assertOk()->assertJsonPath('data.status', 'on_sale');
        $this->withToken($token)->withHeaders($headers)->postJson("/api/v1/admin/products/{$id}/status", ['action' => 'publish', 'reason' => '重复发布请求。'])
            ->assertOk()->assertJsonPath('data.status', 'on_sale');

        $mini = $this->withHeader('X-Client-Platform', 'wechat_mini')->getJson("/api/v1/products/{$id}")->assertOk()->json('data.skus.0.purchase_options');
        $inside = $this->withHeader('X-Client-Platform', 'wechat_h5')->getJson("/api/v1/products/{$id}")->assertOk()->json('data.skus.0.purchase_options');
        $outside = $this->withHeader('X-Client-Platform', 'web_h5')->getJson("/api/v1/products/{$id}")->assertOk()->json('data.skus.0.purchase_options');
        $this->assertSame($mini, $inside);
        $this->assertSame($mini, $outside);
        $this->assertDatabaseCount('product_status_changes', 1);

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'product-off-shelf-000001'])->postJson("/api/v1/admin/products/{$id}/status", ['action' => 'off_shelf', 'reason' => '运营主动下架。'])
            ->assertOk()->assertJsonPath('data.status', 'off_shelf');
        $this->getJson("/api/v1/products/{$id}")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/admin/products/{$id}")->assertOk()->assertJsonPath('data.status', 'off_shelf');
    }

    public function test_purchase_option_uses_optimistic_lock_and_cannot_disable_the_last_mode(): void
    {
        $token = $this->adminToken('product-option-root');
        $category = $this->category($token, 'option-goods');
        $created = $this->createProduct($token, $category, 'OPTION-001', 'option-product-create-01');
        $productId = $created['id'];
        $skuId = $created['skus'][0]['id'];

        $this->withToken($token)->putJson("/api/v1/admin/products/{$productId}/skus/{$skuId}/purchase-options/cash", [
            'cash_amount' => 2090, 'points' => 0, 'enabled' => true, 'version' => 1,
        ])->assertOk()->assertJsonPath('data.skus.0.purchase_options.0.cash_amount', 2090)->assertJsonPath('data.skus.0.purchase_options.0.version', 2);
        $this->withToken($token)->putJson("/api/v1/admin/products/{$productId}/skus/{$skuId}/purchase-options/cash", [
            'cash_amount' => 2190, 'points' => 0, 'enabled' => true, 'version' => 1,
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        foreach (['cash', 'points'] as $mode) {
            $option = collect($this->withToken($token)->getJson("/api/v1/admin/products/{$productId}")->json('data.skus.0.purchase_options'))->firstWhere('purchase_mode', $mode);
            $this->withToken($token)->putJson("/api/v1/admin/products/{$productId}/skus/{$skuId}/purchase-options/{$mode}", [
                'cash_amount' => $option['cash_amount'], 'points' => $option['points'], 'enabled' => false, 'version' => $option['version'],
            ])->assertOk();
        }
        $last = collect($this->withToken($token)->getJson("/api/v1/admin/products/{$productId}")->json('data.skus.0.purchase_options'))->firstWhere('purchase_mode', 'cash_points');
        $this->withToken($token)->putJson("/api/v1/admin/products/{$productId}/skus/{$skuId}/purchase-options/cash_points", [
            'cash_amount' => $last['cash_amount'], 'points' => $last['points'], 'enabled' => false, 'version' => $last['version'],
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseHas('sku_purchase_options', ['purchase_mode' => 'cash_points', 'enabled' => true]);
    }

    public function test_product_update_creates_append_only_version_and_checks_lock(): void
    {
        $token = $this->adminToken('product-version-root');
        $category = $this->category($token, 'version-goods');
        $created = $this->createProduct($token, $category, 'VERSION-001', 'version-product-create-1');
        $payload = $this->product($category, 'VERSION-002');
        $payload['name'] = '更新后的积分礼品';
        $payload['version'] = 1;
        $this->withToken($token)->putJson('/api/v1/admin/products/'.$created['id'], $payload)
            ->assertOk()->assertJsonPath('data.name', '更新后的积分礼品')->assertJsonPath('data.version', 2)->assertJsonPath('data.definition_version', 2);
        $this->withToken($token)->putJson('/api/v1/admin/products/'.$created['id'], $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('product_versions', 2);

        $version = ProductVersion::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $version->update(['changed_fields' => ['tampered']]);
    }

    private function category(string $token, string $slug = 'daily-goods'): string
    {
        return $this->withToken($token)->postJson('/api/v1/admin/product-categories', ['name' => '日用好物', 'slug' => $slug])
            ->assertCreated()->json('data.id');
    }

    private function createProduct(string $token, string $categoryId, string $skuCode, string $key, ?string $shippingTemplateId = null): array
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/admin/products', $this->product($categoryId, $skuCode, $shippingTemplateId))
            ->assertCreated()->json('data');
    }

    private function product(string $categoryId, string $skuCode = 'GIFT-001', ?string $shippingTemplateId = null): array
    {
        return [
            'category_id' => $categoryId, 'name' => '积分商城保温杯', 'subtitle' => '三种方式任选', 'description' => '统一价格、统一货品资料。',
            'skus' => [[
                'sku_code' => $skuCode, 'name' => '白色 450ml', 'specs' => ['颜色' => '白色', '容量' => '450ml'], 'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplateId,
                'purchase_options' => [
                    ['purchase_mode' => 'cash', 'cash_amount' => 1990, 'points' => 0, 'enabled' => true],
                    ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => 2600, 'enabled' => true],
                    ['purchase_mode' => 'cash_points', 'cash_amount' => 990, 'points' => 1200, 'enabled' => true],
                ],
            ]],
        ];
    }

    private function shippingTemplate(string $token): string
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => 'product-test-shipping-001'])->postJson('/api/v1/admin/shipping-templates', [
            'name' => '全国固定运费', 'charge_type' => 'fixed', 'fixed_amount' => 600,
            'regions' => [['region_codes' => ['*'], 'deliverable' => true, 'remote_surcharge_amount' => 0]],
            'free_rules' => [], 'enabled' => true,
        ])->assertCreated()->json('data.id');
    }

    private function adminToken(string $account = 'product-root'): string
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
