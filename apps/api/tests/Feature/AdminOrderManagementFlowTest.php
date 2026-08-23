<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Order\Http\Controllers\AdminOrderController;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSku;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderManagementFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth.admin', 'admin.permission:fulfillment.manage|after_sale.review|finance.manage'])
            ->get('/api/v1/test-admin/orders', [AdminOrderController::class, 'index']);
        Route::middleware(['api', 'auth.admin', 'admin.permission:fulfillment.manage|after_sale.review|finance.manage'])
            ->get('/api/v1/test-admin/orders/{order}', [AdminOrderController::class, 'show']);
    }

    public function test_authorized_admin_can_filter_search_and_paginate_orders(): void
    {
        $this->seed(RbacSeeder::class);
        [$warehouse, $warehouseToken] = $this->admin('warehouse-reader', 'warehouse');
        [, $platformToken] = $this->admin('platform-reader', 'platform_admin');
        $user = $this->customer('13800139001');

        foreach (range(1, 12) as $index) {
            $this->order(
                $warehouse,
                $user,
                'ADMIN-ORDER-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $index <= 7 ? 'pending_fulfillment' : 'completed',
            );
        }

        $this->withToken($platformToken)->getJson('/api/v1/test-admin/orders')
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');

        $page = $this->withToken($warehouseToken)->getJson('/api/v1/test-admin/orders?page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 12)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.summary.pending_fulfillment', 7)
            ->assertJsonCount(10, 'data.items');

        $page->assertJsonStructure(['data' => ['items' => [[
            'id', 'order_no', 'status', 'fulfillment_type', 'cash_payment_method',
            'payable_cash_amount', 'payable_points', 'paid_wallet_amount', 'paid_external_amount',
            'paid_points', 'refunded_wallet_amount', 'refunded_external_amount', 'refunded_points',
            'shipping_amount', 'user' => ['id', 'nickname', 'phone_masked'],
            'items', 'fulfillments', 'created_at', 'paid_at', 'fulfill_by',
        ]], 'pagination', 'summary']]);
        $this->assertSame('138****9001', $page->json('data.items.0.user.phone_masked'));

        $this->withToken($warehouseToken)
            ->getJson('/api/v1/test-admin/orders?status=completed&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.summary.status_counts.completed', 5);

        $this->withToken($warehouseToken)
            ->getJson('/api/v1/test-admin/orders?search=ADMIN-ORDER-03&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.order_no', 'ADMIN-ORDER-03');

        $this->withToken($warehouseToken)
            ->getJson('/api/v1/test-admin/orders?search=13800139001&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 12);
    }

    public function test_detail_includes_fulfillment_counts_timeline_and_permission_aware_address(): void
    {
        $this->seed(RbacSeeder::class);
        [$warehouse, $warehouseToken] = $this->admin('warehouse-detail', 'warehouse');
        [, $financeToken] = $this->admin('finance-detail', 'finance');
        $user = $this->customer('13800139002');
        [$order, $item] = $this->order($warehouse, $user, 'ADMIN-DETAIL-01', 'pending_fulfillment', 3, 1);

        $fulfillment = Fulfillment::query()->create([
            'fulfillment_no' => 'FULFILLMENT-DETAIL-01',
            'order_id' => $order->id,
            'method' => 'logistics',
            'status' => 'shipped',
            'carrier_code' => 'SF',
            'carrier_name' => '顺丰速运',
            'tracking_no' => 'SF123456789',
            'idempotency_key_hash' => hash('sha256', 'admin-detail-fulfillment'),
            'created_by_admin_id' => $warehouse->id,
            'fulfilled_at' => now(),
        ]);
        DB::table('fulfillment_items')->insert([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $item->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseDetail = $this->withToken($warehouseToken)
            ->getJson('/api/v1/test-admin/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.items.0.fulfilled_quantity', 1)
            ->assertJsonPath('data.items.0.refunded_quantity', 1)
            ->assertJsonPath('data.items.0.remaining_fulfillment_quantity', 1)
            ->assertJsonPath('data.fulfillments.0.items.0.order_item_id', $item->public_id)
            ->assertJsonPath('data.address_snapshot.phone', '13800139002')
            ->assertJsonPath('data.status_changes.0.to_status', 'pending_fulfillment');
        $warehouseDetail->assertJsonStructure(['data' => ['address_snapshot', 'status_changes', 'after_sale_summary']]);

        $this->withToken($financeToken)
            ->getJson('/api/v1/test-admin/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.address_snapshot.phone', '138****9002')
            ->assertJsonPath('data.address_snapshot.recipient_name', '张*');
    }

    /** @return array{AdminUser, string} */
    private function admin(string $account, string $roleCode): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function customer(string $phone): User
    {
        return User::factory()->create([
            'nickname' => '订单用户',
            'phone' => $phone,
            'phone_hash' => app(PhoneVerificationService::class)->phoneHash($phone),
        ]);
    }

    /** @return array{Order, OrderItem} */
    private function order(
        AdminUser $admin,
        User $user,
        string $orderNo,
        string $status,
        int $quantity = 1,
        int $refundedQuantity = 0,
    ): array {
        $checkoutPreviewId = DB::table('checkout_previews')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $orderNo.'-token'),
            'request_hash' => hash('sha256', $orderNo.'-request'),
            'request_snapshot' => '{}',
            'pricing_snapshot' => '{}',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchId = DB::table('order_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'batch_no' => 'B-'.$orderNo,
            'user_id' => $user->id,
            'client_platform' => 'h5',
            'capability_version' => 1,
            'status' => 'active',
            'idempotency_key_hash' => hash('sha256', $orderNo.'-batch'),
            'checkout_preview_id' => $checkoutPreviewId,
            'items_cash_amount' => 1000,
            'items_points' => 100,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 100,
            'payable_cash_amount' => 1100,
            'payable_points' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'name' => '后台订单测试商品',
            'description' => '订单详情只读展示测试。',
            'status' => 'published',
            'idempotency_key_hash' => hash('sha256', $orderNo.'-product'),
            'created_by_admin_id' => $admin->id,
        ]);
        $sku = ProductSku::query()->create([
            'product_id' => $product->id,
            'sku_code' => 'SKU-'.$orderNo,
            'name' => '白色 450ml',
            'specs' => ['颜色' => '白色', '容量' => '450ml'],
            'fulfillment_type' => 'logistics',
        ]);
        $order = Order::query()->create([
            'order_no' => $orderNo,
            'order_batch_id' => $batchId,
            'user_id' => $user->id,
            'fulfillment_type' => 'logistics',
            'status' => $status,
            'cash_payment_method' => 'wechat',
            'address_snapshot' => [
                'recipient_name' => '张三',
                'phone' => $user->phone,
                'province' => '湖北省',
                'city' => '武汉市',
                'district' => '洪山区',
                'detail' => '示例路 1 号',
                'postal_code' => '430000',
            ],
            'items_cash_amount' => 1000,
            'items_points' => 100,
            'cash_discount_amount' => 0,
            'points_discount' => 0,
            'shipping_amount' => 100,
            'payable_cash_amount' => 1100,
            'payable_points' => 100,
            'paid_wallet_amount' => 0,
            'paid_external_amount' => 1100,
            'paid_points' => 100,
            'paid_at' => now(),
            'fulfill_by' => now()->addDay(),
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_sku_id' => $sku->id,
            'product_public_id' => $product->public_id,
            'sku_public_id' => $sku->public_id,
            'product_name' => $product->name,
            'sku_name' => $sku->name,
            'specs_snapshot' => $sku->specs,
            'purchase_mode' => 'cash_points',
            'fulfillment_type' => 'logistics',
            'quantity' => $quantity,
            'unit_cash_amount' => 1000,
            'unit_points' => 100,
            'cash_subtotal' => 1000,
            'points_subtotal' => 100,
            'paid_cash_amount' => 1100,
            'paid_points' => 100,
            'refunded_quantity' => $refundedQuantity,
            'purchase_limit_snapshot' => [],
        ]);
        DB::table('order_status_changes')->insert([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $status,
            'event' => 'created',
            'reason' => '测试订单创建。',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order, $item];
    }
}
