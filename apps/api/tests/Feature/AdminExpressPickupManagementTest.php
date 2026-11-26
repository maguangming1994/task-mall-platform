<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use App\Modules\ExpressPickup\Models\PrinterPrintJob;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminExpressPickupManagementTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->campus = Campus::query()->firstOrFail();
        Http::fake([
            'https://api.feieyun.cn/Api/Open/' => Http::response(['ret' => 0, 'msg' => 'ok', 'data' => '在线，工作状态正常。']),
        ]);
    }

    public function test_permissions_scope_orders_runners_settlements_and_printers(): void
    {
        [, $auditorToken] = $this->admin('auditor', 'ep-auditor');
        [, $operationsToken] = $this->admin('operations_admin', 'ep-operations');
        [, $financeToken] = $this->admin('finance', 'ep-finance');

        $this->withToken($auditorToken)->getJson('/api/v1/admin/express-pickup/orders')->assertForbidden();
        $this->withToken($operationsToken)->getJson('/api/v1/admin/express-pickup/orders')->assertOk();
        $this->withToken($operationsToken)->postJson('/api/v1/admin/express-pickup/settlements', [
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        ])->assertForbidden();
        $this->withToken($financeToken)->getJson('/api/v1/admin/express-pickup/settlements')->assertOk();

        $navigation = $this->withToken($operationsToken)->getJson('/api/v1/admin/navigation')->assertOk()->json('data');
        $this->assertSame('/express-pickup', collect($navigation)->firstWhere('code', 'express_pickup')['path']);
    }

    public function test_printer_crud_test_preview_and_secret_never_echoed(): void
    {
        [, $token] = $this->admin('operations_admin', 'ep-printer-admin');

        $response = $this->withToken($token)->postJson('/api/v1/admin/express-pickup/printers', [
            'campus_id' => $this->campus->id,
            'name' => '菜鸟驿站东门',
            'vendor' => 'feie',
            'sn' => 'FEIE-SN-9001',
            'username' => 'feie-user',
            'secret' => 'feie-ukey-9001',
            'paper_width' => 58,
            'template' => "取件码：\${pickup_code}\n订单：\${order_no}",
            'status' => 'enabled',
        ])->assertCreated()
            ->assertJsonPath('data.vendor_label', '飞鹅')
            ->assertJsonPath('data.secret_configured', true);

        $this->assertStringNotContainsString('feie-ukey-9001', $response->getContent());
        $printerId = $response->json('data.id');
        $raw = DB::table('campus_printers')->where('public_id', $printerId)->value('secret_encrypted');
        $this->assertNotSame('feie-ukey-9001', $raw);

        $this->withToken($token)->postJson("/api/v1/admin/express-pickup/printers/{$printerId}/test")
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('success', CampusPrinter::query()->where('public_id', $printerId)->value('last_test_status'));

        $preview = $this->withToken($token)->postJson('/api/v1/admin/express-pickup/printers/preview', [
            'template' => '取件码：${pickup_code} / 订单：${order_no}',
            'paper_width' => 80,
        ])->assertOk()->json('data.content');
        $this->assertStringContainsString('8-2-3021', $preview);
        $this->assertStringContainsString('EP20260101120000ABCDEF', $preview);

        $this->withToken($token)->putJson("/api/v1/admin/express-pickup/printers/{$printerId}", [
            'name' => '菜鸟驿站东门（新）',
            'vendor' => 'feie',
            'sn' => 'FEIE-SN-9001',
            'username' => 'feie-user',
            'paper_width' => 80,
            'status' => 'disabled',
        ])->assertOk()
            ->assertJsonPath('data.name', '菜鸟驿站东门（新）')
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.secret_configured', true);

        $this->withToken($token)->deleteJson("/api/v1/admin/express-pickup/printers/{$printerId}")->assertOk();
        $this->assertSame(0, CampusPrinter::query()->count());

        $this->assertSame(1, DB::table('operation_logs')->where('action', 'express_pickup.printer.create')->count());
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'express_pickup.printer.delete')->count());
    }

    public function test_runner_review_and_order_reprint_with_audit(): void
    {
        [, $token] = $this->admin('operations_admin', 'ep-runner-admin');
        $applicant = $this->user('13800009001');
        $runner = ExpressPickupRunner::query()->create([
            'campus_id' => $this->campus->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'real_name' => '刘同学',
            'phone' => '13800009001',
            'applied_at' => now(),
        ]);

        $this->withToken($token)->postJson("/api/v1/admin/express-pickup/runners/{$runner->public_id}/review", [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->withToken($token)->postJson("/api/v1/admin/express-pickup/runners/{$runner->public_id}/review", [
            'status' => 'rejected', 'reason' => '资料不清晰',
        ])->assertOk()->assertJsonPath('data.reject_reason', '资料不清晰');

        $printer = new CampusPrinter([
            'campus_id' => $this->campus->id,
            'name' => '飞鹅打印机',
            'vendor' => CampusPrinter::VENDOR_FEIE,
            'sn' => 'FEIE-SN-7777',
            'username' => 'feie-user',
            'paper_width' => 58,
            'status' => 'enabled',
        ]);
        $printer->setSecret('feie-ukey-7777');
        $printer->save();

        $owner = $this->user('13800009002');
        $order = ExpressPickupOrder::query()->create([
            'order_no' => 'EP202601010000TEST01',
            'campus_id' => $this->campus->id,
            'user_id' => $owner->id,
            'status' => ExpressPickupOrder::STATUS_PENDING_ACCEPT,
            'pickup_site' => '驿站 E',
            'pickup_code' => '5-5-5',
            'package_count' => 1,
            'deliver_building' => '5 号楼',
            'deliver_address' => '505',
            'contact_name' => '孙同学',
            'contact_phone' => '13800009002',
            'reward_cents' => 600,
            'total_cents' => 600,
            'payment_method' => 'wallet',
            'paid_at' => now(),
            'paid_amount_cents' => 600,
        ]);

        $this->withToken($token)->postJson("/api/v1/admin/express-pickup/orders/{$order->public_id}/reprint")
            ->assertOk()->assertJsonPath('data.status', 'printed');
        $this->assertSame('printed', PrinterPrintJob::query()->where('business_id', $order->public_id)->value('status'));
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'express_pickup.order.reprint')->count());

        $detail = $this->withToken($token)->getJson("/api/v1/admin/express-pickup/orders/{$order->public_id}")
            ->assertOk()->json('data');
        $this->assertSame('驿站 E', $detail['pickup_site']);
        $this->assertSame('5-5-5', $detail['pickup_code']);

        $list = $this->withToken($token)->getJson('/api/v1/admin/express-pickup/orders?keyword=EP202601010000TEST01')
            ->assertOk()->json('data');
        $this->assertCount(1, $list['items']);
        $this->assertSame('5***5', $list['items'][0]['pickup_code']);
        $this->assertSame('138****9002', $list['items'][0]['contact_phone']);
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

    private function admin(string $roleCode, string $account): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
