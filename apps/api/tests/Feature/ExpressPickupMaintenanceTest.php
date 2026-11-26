<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Auth\Services\AccessTokenService;
use App\Modules\Campus\Models\Campus;
use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use App\Modules\ExpressPickup\Models\PrinterPrintJob;
use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use App\Modules\Payment\Models\Payment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpressPickupMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->campus = Campus::query()->firstOrFail();
    }

    public function test_unpaid_orders_expire_after_fifteen_minutes(): void
    {
        $owner = $this->userWithWallet('13900001001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->createOrder($token, 'ep-maint-unpaid-001', '13900001001');

        $this->travel(16)->minutes();
        $this->artisan('express-pickup:expire-orders')->assertSuccessful();

        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertSame(ExpressPickupOrder::STATUS_CANCELLED, $order->status);
        $this->assertNull($order->refunded_at);
        $this->assertSame(10000, $this->wallet($owner));
        $this->assertDatabaseHas('express_pickup_order_events', [
            'order_id' => $order->id,
            'event' => 'cancelled',
            'actor_type' => 'system',
        ]);

        $this->travelBack();
    }

    public function test_paid_orders_without_runner_are_cancelled_and_refunded(): void
    {
        $owner = $this->userWithWallet('13900002001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->createOrder($token, 'ep-maint-stale-001', '13900002001');

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-maint-stale-pay-1')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk();
        $this->assertSame(9500, $this->wallet($owner));

        $this->travel(31)->minutes();
        $this->artisan('express-pickup:expire-orders')->assertSuccessful();

        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertSame(ExpressPickupOrder::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame(10000, $this->wallet($owner));

        $this->travelBack();
    }

    public function test_delivered_orders_auto_confirm_after_one_day(): void
    {
        $owner = $this->userWithWallet('13900003001', 10000);
        $token = $this->userToken($owner);
        $runner = $this->runner('13900003002');
        $orderId = $this->createOrder($token, 'ep-maint-delivered-1', '13900003001');

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-maint-delivered-pay')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk();

        $runnerToken = $this->userToken($runner);
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/accept")->assertOk();
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/pickup")->assertOk();
        $this->withToken($runnerToken)->postJson("/api/v1/express-pickup/runner/orders/{$orderId}/deliver")
            ->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->travel(25)->hours();
        $this->artisan('express-pickup:expire-orders')->assertSuccessful();

        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertSame(ExpressPickupOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertDatabaseHas('express_pickup_order_events', [
            'order_id' => $order->id,
            'event' => 'auto_completed',
            'actor_type' => 'system',
        ]);

        $this->travelBack();
    }

    public function test_wechat_paid_order_cancel_submits_provider_refund(): void
    {
        $owner = $this->userWithWallet('13900004001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->createOrder($token, 'ep-maint-wechat-0001', '13900004001', 800);

        $order = ExpressPickupOrder::query()->where('public_id', $orderId)->firstOrFail();
        app(ExpressPickupOrderService::class)->markPaid($order, 'wechat', 800);
        Payment::query()->create([
            'payment_no' => 'P'.now()->format('YmdHis').'WX0001',
            'express_pickup_order_id' => $order->id,
            'user_id' => $owner->id,
            'channel' => 'wechat',
            'scene' => 'wechat-mini',
            'cash_amount' => 800,
            'status' => 'succeeded',
            'idempotency_key_hash' => hash('sha256', 'ep-maint-wechat-payment-1'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->withToken($token)->postJson("/api/v1/express-pickup/orders/{$orderId}/cancel", ['reason' => '不想要了'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $order->refresh();
        $this->assertSame('EPR'.$order->order_no, $order->refund_no);
        $this->assertSame(ExpressPickupOrder::REFUND_STATUS_PROCESSING, $order->refund_status);
        $this->assertNull($order->refunded_at);
        $this->assertSame(10000, $this->wallet($owner));
    }

    public function test_failed_print_job_is_retried(): void
    {
        $calls = 0;
        Http::fake([
            'https://api.feieyun.cn/Api/Open/' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response(['ret' => 1001, 'msg' => '打印机离线'], 200)
                    : Http::response(['ret' => 0, 'msg' => 'ok', 'data' => 'ok']);
            },
        ]);
        $this->printer();
        $owner = $this->userWithWallet('13900005001', 10000);
        $token = $this->userToken($owner);
        $orderId = $this->createOrder($token, 'ep-maint-print-0001', '13900005001');

        $this->withToken($token)->withHeader('Idempotency-Key', 'ep-maint-print-pay-01')
            ->postJson("/api/v1/express-pickup/orders/{$orderId}/payments", ['method' => 'wallet'])
            ->assertOk();
        $this->assertSame('failed', PrinterPrintJob::query()->where('business_id', $orderId)->value('status'));

        $this->artisan('express-pickup:retry-prints')->assertSuccessful();

        $job = PrinterPrintJob::query()->where('business_id', $orderId)->firstOrFail();
        $this->assertSame('printed', $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertNotNull($job->printed_at);
        $this->assertSame('printed', ExpressPickupOrder::query()->where('public_id', $orderId)->value('print_status'));
    }

    private function createOrder(string $token, string $key, string $phone, int $reward = 500): string
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/express-pickup/orders', [
                'pickup_site' => '菜鸟驿站（东门）',
                'pickup_code' => '8-2-3021',
                'package_info' => '纸箱小件',
                'package_count' => 1,
                'deliver_building' => '学生公寓 3 号楼',
                'deliver_address' => '3-412',
                'contact_name' => '张同学',
                'contact_phone' => $phone,
                'reward_cents' => $reward,
            ])->assertCreated()->json('data.id');
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
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
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
            'device_fingerprint_hash' => hash('sha256', 'ep-maint-device-'.$user->id),
            'client_platform' => 'wechat-mini',
            'last_active_at' => now(),
        ]);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'refresh_token_hash' => hash('sha256', 'ep-maint-refresh-'.$user->id),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return app(AccessTokenService::class)->issue($user, $session)['token'];
    }
}
