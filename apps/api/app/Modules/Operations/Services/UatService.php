<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\UatRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UatService
{
    public function __construct(
        private readonly ResilienceService $resilience,
        private readonly ProductionReadinessService $productionReadiness,
    ) {}

    public function run(string $releaseVersion, string $automatedSuiteStatus, bool $externalChannelsExecuted = false, bool $realDeviceExecuted = false): UatRun
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,80}$/', $releaseVersion)) {
            throw new InvalidArgumentException('Release version must contain only letters, numbers, dot, underscore and dash.');
        }
        if (! in_array($automatedSuiteStatus, ['passed', 'failed'], true)) {
            throw new InvalidArgumentException('Automated suite status must be passed or failed.');
        }

        $readiness = $this->resilience->readiness();
        $consistency = $this->resilience->consistencyAudit();
        $production = $this->productionReadiness->evaluate();
        $routes = $this->routeCoverage();
        $commands = $this->commandCoverage();

        $cases = [
            ['case_code' => 'automated_regression', 'scope' => 'local', 'status' => $automatedSuiteStatus, 'evidence' => ['source' => 'scripts/check.ps1']],
            ['case_code' => 'runtime_dependencies', 'scope' => 'local', 'status' => $readiness['status'] === 'ready' ? 'passed' : 'failed', 'evidence' => ['checks' => $readiness['checks']]],
            ['case_code' => 'business_consistency', 'scope' => 'local', 'status' => $consistency['healthy'] ? 'passed' : 'failed', 'evidence' => ['checks' => $consistency['checks']]],
            ['case_code' => 'route_contract', 'scope' => 'local', 'status' => $routes['missing'] === [] ? 'passed' : 'failed', 'evidence' => $routes],
            ['case_code' => 'scheduler_coverage', 'scope' => 'local', 'status' => $commands['missing'] === [] ? 'passed' : 'failed', 'evidence' => $commands],
            ['case_code' => 'external_channels', 'scope' => 'external', 'status' => $externalChannelsExecuted && $production['ready'] ? 'passed' : ($externalChannelsExecuted ? 'failed' : 'blocked'), 'evidence' => ['credentials_ready' => $production['ready'], 'failed_checks' => $production['summary']['failed']]],
            ['case_code' => 'real_device_matrix', 'scope' => 'external', 'status' => $realDeviceExecuted ? 'passed' : 'blocked', 'evidence' => ['required_clients' => ['wechat-mini', 'wechat-h5', 'browser-h5']]],
        ];
        $localFailed = collect($cases)->contains(fn (array $case) => $case['scope'] === 'local' && $case['status'] === 'failed');
        $externalFailed = collect($cases)->contains(fn (array $case) => $case['scope'] === 'external' && $case['status'] === 'failed');
        $status = $localFailed || $externalFailed ? 'failed' : ($externalChannelsExecuted && $realDeviceExecuted ? 'passed' : 'passed_local');
        $summary = ['passed' => collect($cases)->where('status', 'passed')->count(), 'failed' => collect($cases)->where('status', 'failed')->count(), 'blocked' => collect($cases)->where('status', 'blocked')->count(), 'total' => count($cases)];
        $hash = hash('sha256', json_encode(['release_version' => $releaseVersion, 'environment' => app()->environment(), 'status' => $status, 'summary' => $summary, 'cases' => $cases, 'nonce' => Str::ulid()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($releaseVersion, $automatedSuiteStatus, $externalChannelsExecuted, $realDeviceExecuted, $status, $summary, $hash, $cases): UatRun {
            $run = UatRun::query()->create(['release_version' => $releaseVersion, 'environment' => app()->environment(), 'status' => $status, 'automated_suite_status' => $automatedSuiteStatus, 'external_channels_executed' => $externalChannelsExecuted, 'real_device_executed' => $realDeviceExecuted, 'summary' => $summary, 'report_hash' => $hash, 'completed_at' => now()]);
            $run->cases()->createMany($cases);

            return $run->load('cases');
        });
    }

    private function routeCoverage(): array
    {
        $required = ['api/v1/auth/phone/login', 'api/v1/tasks', 'api/v1/products', 'api/v1/checkout/preview', 'api/v1/orders', 'api/v1/notifications', 'api/v1/admin/operations/dashboard'];
        $available = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        return ['required' => $required, 'missing' => array_values(array_diff($required, $available))];
    }

    private function commandCoverage(): array
    {
        $required = ['schedule:run', 'notifications:deliver', 'payments:sync-pending', 'after-sales:close-expired', 'privacy:replay-deletions', 'system:consistency-audit', 'robot:dispatch-scheduled'];
        $available = array_keys(Artisan::all());

        return ['required' => $required, 'missing' => array_values(array_diff($required, $available))];
    }
}
