<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRefund;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecondHandAuditRatingRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_item_requires_audit_before_listing(): void
    {
        [$seller, $sellerToken] = $this->loginAndConsent('13800138001');
        [$buyer, $buyerToken] = $this->loginAndConsent('13800138002');
        [$admin, $adminToken] = $this->loginAdmin();

        // 发布闲置，默认进入待审核
        $item = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '九成新 iPhone 15 Pro',
            'description' => '自用无拆修，电池健康度 92%',
            'price' => 3999.99,
            'condition' => 'like_new',
        ])->assertCreated()->assertJsonPath('data.audit_status', 'pending');

        $itemId = $item->json('data.id');

        // 待审核商品不出现在公开列表
        $this->getJson('/api/v1/second-hand/items')->assertOk()
            ->assertJsonCount(0, 'data.items');

        // 非本人（另一个用户）查看详情 404
        $this->withToken($buyerToken)->getJson('/api/v1/second-hand/items/'.$itemId)->assertNotFound();

        // 本人可查看
        $this->withToken($sellerToken)->getJson('/api/v1/second-hand/items/'.$itemId)->assertOk();

        // 管理员审核通过
        $this->withToken($adminToken)->getJson('/api/v1/admin/second-hand/items?audit_status=pending')
            ->assertOk()->assertJsonCount(1, 'data.items');

        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$itemId.'/review', [
            'decision' => 'approve',
        ])->assertOk()->assertJsonPath('data.audit_status', 'approved');

        // 审核后出现在公开列表
        $this->getJson('/api/v1/second-hand/items')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $itemId);
    }

    public function test_rating_after_order_completion(): void
    {
        [$seller, $sellerToken] = $this->loginAndConsent('13800138002');
        [$buyer, $buyerToken] = $this->loginAndConsent('13800138003');
        [$admin, $adminToken] = $this->loginAdmin();

        $this->depositWallet($buyer, 10000);

        // 卖家发布并审核通过
        $item = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '测试商品',
            'price' => 100,
        ])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$item->json('data.id').'/review', ['decision' => 'approve'])->assertOk();

        // 买家下单并支付
        $order = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders', [
            'item_id' => $item->json('data.id'),
        ])->assertCreated();

        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/pay')->assertOk();

        // 卖家发货
        $this->withToken($sellerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/ship')->assertOk();

        // 买家收货
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/complete')->assertOk();

        // 买家评价卖家
        $rating = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/rate', [
            'score' => 5,
            'content' => '商品和描述一致，卖家发货快',
        ])->assertCreated()->assertJsonPath('data.score', 5);

        // 卖家回复评价
        $this->withToken($sellerToken)->putJson('/api/v1/second-hand/ratings/'.$rating->json('data.id').'/reply', [
            'reply_content' => '感谢支持！',
        ])->assertOk()->assertJsonPath('data.reply_content', '感谢支持！');

        // 查看用户评价列表
        $this->getJson('/api/v1/users/'.$seller->public_id.'/second-hand-ratings')
            ->assertOk()->assertJsonPath('data.average_score', 5);
    }

    public function test_refund_flow_with_seller_approve(): void
    {
        [$seller, $sellerToken] = $this->loginAndConsent('13800138004');
        [$buyer, $buyerToken] = $this->loginAndConsent('13800138005');
        [$admin, $adminToken] = $this->loginAdmin();

        // 准备钱包余额
        $this->depositWallet($buyer, 10000);

        // 发布并审核通过
        $item = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '退款测试商品',
            'price' => 50,
        ])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$item->json('data.id').'/review', ['decision' => 'approve'])->assertOk();

        // 下单并支付
        $order = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders', [
            'item_id' => $item->json('data.id'),
        ])->assertCreated();
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/pay')->assertOk();

        $orderId = $order->json('data.id');

        // 买家申请退款
        $refund = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$orderId.'/refund', [
            'reason' => '商品与描述不符',
            'images' => [],
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $refundId = $refund->json('data.id');

        // 卖家同意退款
        $this->withToken($sellerToken)->postJson('/api/v1/second-hand/orders/'.$orderId.'/refund/respond', [
            'action' => 'approve',
            'seller_note' => '同意退款',
        ])->assertOk()->assertJsonPath('data.status', 'seller_approved');

        // 订单状态变为 refunded
        $this->withToken($buyerToken)->getJson('/api/v1/second-hand/orders/'.$orderId)
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        // 买家钱包余额恢复
        $buyer->refresh();
        $this->assertEquals(10000, $buyer->assetAccount?->wallet_available_cents);
    }

    public function test_refund_flow_with_admin_arbitration(): void
    {
        [$seller, $sellerToken] = $this->loginAndConsent('13800138006');
        [$buyer, $buyerToken] = $this->loginAndConsent('13800138007');
        [$admin, $adminToken] = $this->loginAdmin();

        $this->depositWallet($buyer, 10000);

        $item = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '仲裁测试商品',
            'price' => 80,
        ])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$item->json('data.id').'/review', ['decision' => 'approve'])->assertOk();

        $order = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders', [
            'item_id' => $item->json('data.id'),
        ])->assertCreated();
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$order->json('data.id').'/pay')->assertOk();

        $orderId = $order->json('data.id');

        // 申请退款
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$orderId.'/refund', [
            'reason' => '质量问题',
        ])->assertCreated();

        // 卖家拒绝
        $this->withToken($sellerToken)->postJson('/api/v1/second-hand/orders/'.$orderId.'/refund/respond', [
            'action' => 'reject',
            'seller_note' => '商品无问题',
        ])->assertOk()->assertJsonPath('data.status', 'seller_rejected');

        // 买家申请平台介入
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders/'.$orderId.'/refund/escalate')
            ->assertOk()->assertJsonPath('data.status', 'arbitrating');

        // 管理员仲裁通过
        $orderModel = SecondHandOrder::query()->where('public_id', $orderId)->firstOrFail();
        $refund = SecondHandRefund::query()->where('order_id', $orderModel->id)->firstOrFail();
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/refunds/'.$refund->public_id.'/arbitrate', [
            'decision' => 'approve',
            'note' => '支持买家',
        ])->assertOk()->assertJsonPath('data.status', 'arbitrated_approved');

        // 订单状态 refunded
        $this->withToken($buyerToken)->getJson('/api/v1/second-hand/orders/'.$orderId)
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        // 买家钱包余额恢复
        $buyer->refresh();
        $this->assertEquals(10000, $buyer->assetAccount?->wallet_available_cents);
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'sh-test-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data');
        foreach ($pending as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'sh-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }

    private function loginAdmin(): array
    {
        $admin = AdminUser::query()->create([
            'account' => 'sh-admin-test',
            'display_name' => '闲置测试管理员',
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $login = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'sh-admin-test',
            'password' => 'StrongPassword123!',
        ])->assertOk();

        return [$admin, $login->json('data.access_token')];
    }

    private function depositWallet(User $user, int $cents): void
    {
        $account = UserAssetAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['wallet_available_cents' => 0, 'wallet_frozen_cents' => 0, 'version' => 0]
        );
        $account->increment('wallet_available_cents', $cents);
    }
}
