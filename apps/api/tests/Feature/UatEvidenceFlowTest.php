<?php

namespace Tests\Feature;

use App\Modules\Operations\Models\UatRun;
use App\Modules\Operations\Services\UatService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UatEvidenceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_local_uat_records_immutable_evidence_and_keeps_external_work_visible(): void
    {
        $run = app(UatService::class)->run('v0.29.0-local', 'passed');

        $this->assertSame('passed_local', $run->status);
        $this->assertSame(['passed' => 5, 'failed' => 0, 'blocked' => 2, 'total' => 7], $run->summary);
        $this->assertCount(7, $run->cases);
        $this->assertSame(64, strlen($run->report_hash));
        $this->assertSame('blocked', $run->cases->firstWhere('case_code', 'external_channels')->status);
        $this->assertSame('blocked', $run->cases->firstWhere('case_code', 'real_device_matrix')->status);
        $this->assertDatabaseHas('uat_runs', ['public_id' => $run->public_id, 'status' => 'passed_local']);
        $this->assertDatabaseCount('uat_case_results', 7);

        $this->expectException(LogicException::class);
        $run->update(['status' => 'failed']);
    }

    public function test_failed_automated_suite_fails_strict_uat_and_keeps_audit_report(): void
    {
        $this->artisan('uat:run --release=v0.29.0-failed --automated-suite=failed --strict')->assertExitCode(1);

        $run = UatRun::query()->latest()->with('cases')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->summary['failed']);
        $this->assertSame('failed', $run->cases->firstWhere('case_code', 'automated_regression')->status);
        $this->assertDatabaseCount('uat_runs', 1);
        $this->assertDatabaseCount('uat_case_results', 7);
    }
}
