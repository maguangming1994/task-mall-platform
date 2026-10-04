<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Assets\Models\UserCoupon;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponCenterAndBuyerMessageFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_coupon_center_lists_templates_and_user_can_claim_once(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138201');
        $template = $this->createTemplate('center-claimable', '校内通用现金券', 500, 0, 10);

        $this->withToken($token)->getJson('/api/v1/coupon-center')->assertOk()
            ->assertJsonPath('data.items.0.id', $template->public_id)
            ->assertJsonPath('data.items.0.claimed', false)
            ->assertJsonPath('data.items.0.remaining', 10);

        $this->withToken($token)->postJson('/api/v1/coupon-center/'.$template->public_id.'/claim')
            ->assertCreated()
            ->assertJsonPath('data.name', '校内通用现金券')
            ->assertJsonPath('data.status', 'unused');

        $this->assertSame(1, UserCoupon::query()->where('user_id', $user->id)->where('coupon_template_id', $template->id)->count());
        $this->assertSame(1, (int) UserAssetAccount::query()->where('user_id', $user->id)->value('coupon_available'));
        $this->assertSame(1, (int) $template->fresh()->issued_quantity);

        // 列表状态变为已领取
        $this->withToken($token)->getJson('/api/v1/coupon-center')->assertOk()
            ->assertJsonPath('data.items.0.claimed', true)
            ->assertJsonPath('data.items.0.remaining', 9);
    }

    public function test_duplicate_claim_is_rejected_with_validation_error(): void
    {
        [, $token] = $this->loginAndConsent('13800138202');
        $template = $this->createTemplate('center-duplicate', '重复领取测试券', 0, 100, 5);

        $this->withToken($token)->postJson('/api/v1/coupon-center/'.$template->public_id.'/claim')->assertCreated();
        $this->withToken($token)->postJson('/api/v1/coupon-center/'.$template->public_id.'/claim')
            ->assertUnprocessable()
            ->assertJsonPath('details.errors.coupon_template.0', '您已领取过该优惠券，每人限领一张。');

        $this->assertSame(1, UserCoupon::query()->where('coupon_template_id', $template->id)->count());
    }

    public function test_sold_out_template_is_hidden_and_not_claimable(): void
    {
        [, $firstToken] = $this->loginAndConsent('13800138203');
        [, $secondToken] = $this->loginAndConsent('13800138204');
        $template = $this->createTemplate('center-soldout', '限量抢光券', 1000, 0, 1);

        $this->withToken($firstToken)->postJson('/api/v1/coupon-center/'.$template->public_id.'/claim')->assertCreated();

        // 库存为 0 后不再出现在领券中心
        $this->withToken($secondToken)->getJson('/api/v1/coupon-center')->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->withToken($secondToken)->postJson('/api/v1/coupon-center/'.$template->public_id.'/claim')
            ->assertUnprocessable()
            ->assertJsonPath('details.errors.coupon_template.0', '该优惠券已下架或已被抢光。');
    }

    public function test_second_hand_order_persists_and_returns_buyer_message(): void
    {
        [$seller, $sellerToken] = $this->loginAndConsent('13800138205');
        [$buyer, $buyerToken] = $this->loginAndConsent('13800138206');
        [$admin, $adminToken] = $this->loginAdmin();
        $this->depositWallet($buyer, 10000);

        $item = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '带留言的闲置商品', 'price' => 100,
        ])->assertCreated();
        $itemId = $item->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$itemId.'/review', ['decision' => 'approve'])->assertOk();

        $order = $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders', [
            'item_id' => $itemId, 'buyer_message' => '下午六点后宿舍楼下交易',
        ])->assertCreated()->assertJsonPath('data.buyer_message', '下午六点后宿舍楼下交易');

        // 订单详情回显买家留言
        $this->withToken($buyerToken)->getJson('/api/v1/second-hand/orders/'.$order->json('data.id'))
            ->assertOk()->assertJsonPath('data.buyer_message', '下午六点后宿舍楼下交易');
        $this->assertDatabaseHas('second_hand_orders', ['public_id' => $order->json('data.id'), 'buyer_message' => '下午六点后宿舍楼下交易']);

        // 超过 100 字被拒绝
        $this->withToken($buyerToken)->postJson('/api/v1/second-hand/orders', [
            'item_id' => $itemId, 'buyer_message' => str_repeat('留', 101),
        ])->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['buyer_message']]]);
    }

    private function createTemplate(string $key, string $name, int $cashCents, int $points, int $quantity): CouponTemplate
    {
        $admin = AdminUser::query()->create([
            'account' => 'center-admin-'.$key, 'display_name' => '领券中心管理员',
            'password' => 'StrongPassword123!', 'status' => 'active',
        ]);

        return CouponTemplate::query()->create([
            'name' => $name, 'cash_discount_cents' => $cashCents, 'points_discount' => $points,
            'minimum_cash_cents' => 0, 'minimum_points' => 0, 'valid_days' => 15,
            'total_quantity' => $quantity, 'status' => 'active', 'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'coupon-center-template-'.$key),
        ]);
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'center-test-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data');
        foreach ($pending as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'center-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }

    private function loginAdmin(): array
    {
        $admin = AdminUser::query()->create([
            'account' => 'center-admin-login', 'display_name' => '闲置审核管理员',
            'password' => 'StrongPassword123!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $login = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'center-admin-login', 'password' => 'StrongPassword123!',
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
