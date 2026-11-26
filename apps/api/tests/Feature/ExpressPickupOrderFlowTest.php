<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use App\Modules\Campus\Models\Campus;
use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use App\Modules\ExpressPickup\Models\PrinterPrintJob;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpressPickupOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    /** @var array<string, mixed> 飞鹅打印接口返回，单测可覆盖以模拟离线/失败。 */
    private array $feieResponse = ['ret' => 0, 'msg' => 'ok', 'data' => 'ok'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(DatabaseSeeder::class);
        $this->campus = Campus::query()->firstOrFail();
        Http::fake(fn () => Http::response($this->feieResponse));
    }

    public function test_full_flow_payment_print_accept_deliver_confirm_and_settle(): void
    {
        Http::fake([
            'https://api.feieyun.cn/Api/Open/' => Http::response(['ret' => 0, 'msg' => 'ok', 'data' => 'ok']),
        ]);
        $this->printer();
        $owner = $this->userWithWallet('13800001001', 10000);
        $ownerToken = $this->userToken($owner);
        $runner = $this->runner('13800001002');

        $response = $this->withToken($ownerToken)->withHeader('Idempotency-Key', 'ep-create-flow-0001')
            ->postJson('/api/v1/express-pickup/orders', [
                'pickup_site' => '菜鸟驿站（东门）',
                'pickup_code' => '8-2-3021',
                'package_info' => '纸箱小件',
                'package_count' => 1,
                'deliver_building' => '学生公寓 3 号楼',
                'deliver_address' => '3-412',
                'contact_name' => '张同学',
                'contact_phone' => '13800001001',
                'reward_cents' => 500,
                'expected_at' => now()->addHours(2)->toDateTimeString(),
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.total_cents', 500);

        $orderId = $response->json('data.id');
        $this->assertSame(10000, $this->wallet($owner));

        $this->withToken($ownerToken)->withHeader('Idempotency-Key', 'ep-pay-flow-0001')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'pending_accept');

        $this->assertSame(9500, $this->wallet($owner));
        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertNotNull($order->paid_at);
        $this->assertSame('printed', $order->print_status);

        $job = PrinterPrintJob::query()->where('business_id', $orderId)->firstOrFail();
        $this->assertSame('printed', $job->status);
        $this->assertStringContainsString('8-2-3021', $job->content);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['content'], '8-2-3021'));

        $runnerToken = $this->userToken($runner);
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/accept")
            ->assertStatus(422);

        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/pickup")
            ->assertOk()->assertJsonPath('data.status', 'picked_up');
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/deliver")
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->withToken($ownerToken)->postJson("/api/v1/express-pickup/orders/{$orderId}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $adminToken = $this->adminToken('express-settle-admin');
        $settlement = $this->withToken($adminToken)->postJson('/api/v1/admin/express-pickup/settlements', [
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertSame(500, $settlement['total_cents']);

        $this->withToken($adminToken)->postJson("/api/v1/admin/express-pickup/settlements/{$settlement['id']}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withToken($adminToken)->postJson("/api/v1/admin/express-pickup/settlements/{$settlement['id']}/pay")
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(500, $this->wallet($runner));
        $this->assertNotNull(ExpressPickupOrder::query()->where('public_id', $orderId)->value('settled_at'));
    }

    public function test_order_creation_is_idempotent_and_rejects_invalid_payload(): void
    {
        $owner = $this->userWithWallet('13800002001', 10000);
        $token = $this->userToken($owner);
        $payload = [
            'pickup_site' => '驿站 A', 'pickup_code' => '1-1-1',
            'deliver_building' => '1 号楼', 'deliver_address' => '101',
            'contact_name' => '李同学', 'contact_phone' => '13800002001', 'reward_cents' => 300,
        ];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'ep-idem-key-0001')
            ->postJson('/api/v1/express-pickup/orders', $payload)->assertCreated()->json('data.id');
        $second = $this->withToken($token)->withHeader('Idempotency-Key', 'ep-idem-key-0001')
            ->postJson('/api/v1/express-pickup/orders', $payload)->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame(1, ExpressPickupOrder::query()->count());

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-invalid-key-0001')
            ->postJson('/api/v1/express-pickup/orders', [...$payload, 'contact_phone' => '123', 'reward_cents' => 99999])
            ->assertStatus(422);
        $this->assertSame(1, ExpressPickupOrder::query()->count());
    }

    public function test_user_can_cancel_pending_accept_order_and_get_wallet_refund(): void
    {
        $owner = $this->userWithWallet('13800003001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->withToken($token)->withHeader('Idempotency-Key', 'ep-cancel-create-01')
            ->postJson('/api/v1/express-pickup/orders', [
                'pickup_site' => '驿站 B', 'pickup_code' => '2-2-2',
                'deliver_building' => '2 号楼', 'deliver_address' => '202',
                'contact_name' => '王同学', 'contact_phone' => '13800003001', 'reward_cents' => 800,
            ])->assertCreated()->json('data.id');

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-cancel-pay-001')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk();
        $this->assertSame(9200, $this->wallet($owner));

        $this->withToken($token)->postJson("/api/v1/express-pickup/orders/{$orderId}/cancel", ['reason' => '取件时间临时有变'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10000, $this->wallet($owner));
        $this->assertNotNull(ExpressPickupOrder::query()->where('public_id', $orderId)->value('refunded_at'));
    }

    public function test_runner_requires_approved_qualification_to_accept(): void
    {
        $owner = $this->userWithWallet('13800004001', 10000);
        $ownerToken = $this->userToken($owner);
        $orderId = $this->withToken($ownerToken)->withHeader('Idempotency-Key', 'ep-qualify-0001')
            ->postJson('/api/v1/express-pickup/orders', [
                'pickup_site' => '驿站 C', 'pickup_code' => '3-3-3',
                'deliver_building' => '3 号楼', 'deliver_address' => '303',
                'contact_name' => '赵同学', 'contact_phone' => '13800004001', 'reward_cents' => 400,
            ])->assertCreated()->json('data.id');
        $this->withToken($ownerToken)->withHeader('Idempotency-Key', 'ep-qualify-pay-01')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])->assertOk();

        $stranger = $this->user('13800004002');
        $strangerToken = $this->userToken($stranger);
        $this->withToken($strangerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/accept")
            ->assertStatus(422);
        $this->withToken($strangerToken)->getJson('/api/v1/express-pickup/runner/orders')
            ->assertStatus(422);
    }

    public function test_print_failure_does_not_block_payment(): void
    {
        $this->feieResponse = ['ret' => 1001, 'msg' => '打印机离线'];
        $this->printer();
        $owner = $this->userWithWallet('13800005001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->withToken($token)->withHeader('Idempotency-Key', 'ep-printfail-001')
            ->postJson('/api/v1/express-pickup/orders', [
                'pickup_site' => '驿站 D', 'pickup_code' => '4-4-4',
                'deliver_building' => '4 号楼', 'deliver_address' => '404',
                'contact_name' => '周同学', 'contact_phone' => '13800005001', 'reward_cents' => 500,
            ])->assertCreated()->json('data.id');

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-printfail-pay-1')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk()->assertJsonPath('data.order.status', 'pending_accept');

        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertSame('failed', $order->print_status);
        $this->assertSame('failed', PrinterPrintJob::query()->where('business_id', $orderId)->value('status'));
        $this->assertSame(9500, $this->wallet($owner));
    }

    private function printer(): CampusPrinter
    {
        $printer = new CampusPrinter([
            'campus_id' => $this->campus->id,
            'name' => '测试飞鹅打印机',
            'vendor' => CampusPrinter::VENDOR_FEIE,
            'sn' => 'FEIE-SN-0001',
            'username' => 'feie-user',
            'paper_width' => 58,
            'status' => 'enabled',
        ]);
        $printer->setSecret('feie-ukey-secret');
        $printer->save();

        return $printer;
    }

    private function user(string $phone): User
    {
        return User::query()->create([
            'public_id' => (string) Str::ulid(),
            'nickname' => '用户'.$phone,
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'phone_verified_at' => now(),
            'campus_id' => $this->campus->id,
            'status' => 'active',
        ]);
    }

    private function userWithWallet(string $phone, int $cents): User
    {
        $user = $this->user($phone);
        UserAssetAccount::query()->create([
            'user_id' => $user->id,
            'wallet_available_cents' => $cents,
            'wallet_frozen_cents' => 0,
            'version' => 1,
        ]);

        return $user;
    }

    private function runner(string $phone): User
    {
        $user = $this->userWithWallet($phone, 0);
        ExpressPickupRunner::query()->create([
            'campus_id' => $this->campus->id,
            'user_id' => $user->id,
            'status' => 'active',
            'real_name' => '跑腿'.$phone,
            'phone' => $phone,
            'applied_at' => now(),
            'reviewed_at' => now(),
        ]);

        return $user;
    }

    private function wallet(User $user): int
    {
        return (int) UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents');
    }

    private function userToken(User $user): string
    {
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', 'ep-device-'.$user->id),
            'client_platform' => 'wechat-mini',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'ep-refresh-'.$user->id),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return app(AccessTokenService::class)->issue($user, $session)['token'];
    }

    private function adminToken(string $account): string
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }
}
