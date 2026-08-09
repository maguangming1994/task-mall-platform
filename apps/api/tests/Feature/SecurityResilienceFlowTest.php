<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Operations\Services\ResilienceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityResilienceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_liveness_readiness_and_security_headers_fail_without_leaking_details(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy');

        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.database.ready', true)
            ->assertJsonPath('data.checks.cache.ready', true)
            ->assertJsonPath('data.checks.storage.ready', true)
            ->assertJsonMissing(['exception', 'trace', 'password', 'token']);

        $this->postJson('/api/v1/auth/phone/login', [])->assertStatus(422)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_consistency_audit_detects_asset_violation_and_strict_command_fails(): void
    {
        $user = User::factory()->create();
        DB::table('point_batches')->insert([
            'public_id' => '01JTESTRESILIENCE000000001',
            'user_id' => $user->id,
            'source_code' => 'test',
            'issued_points' => 10,
            'remaining_points' => 11,
            'frozen_points' => 0,
            'status' => 'available',
            'event_key_hash' => hash('sha256', 'resilience-test'),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ResilienceService::class)->consistencyAudit();
        $this->assertFalse($result['healthy']);
        $this->assertSame(1, $result['checks']['point_batches']['violations']);
        $this->assertSame(0, $result['checks']['inventory_reservations']['violations']);
        $this->artisan('system:consistency-audit --strict')->assertExitCode(1);
    }

    public function test_recovery_drill_is_reversible_and_performance_indexes_exist(): void
    {
        $before = glob(storage_path('framework/recovery-drill-*')) ?: [];
        $result = app(ResilienceService::class)->recoveryDrill();
        $after = glob(storage_path('framework/recovery-drill-*')) ?: [];

        $this->assertTrue($result['successful']);
        $this->assertTrue($result['checks']['database_transaction']['ready']);
        $this->assertTrue($result['checks']['cache_round_trip']['ready']);
        $this->assertTrue($result['checks']['storage_write']['ready']);
        $this->assertSame($before, $after);
        $this->assertContains('idx_orders_user_status_created', array_column(Schema::getIndexes('orders'), 'name'));
        $this->assertContains('idx_notification_delivery_due', array_column(Schema::getIndexes('notification_deliveries'), 'name'));
        $this->artisan('system:recovery-drill --strict')->assertExitCode(0);
    }
}
