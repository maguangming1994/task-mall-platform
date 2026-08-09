<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\ResilienceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringController extends Controller
{
    public function metrics(Request $request, ResilienceService $resilience): Response
    {
        $expected = (string) config('monitoring.metrics_token');
        abort_unless(strlen($expected) >= 32 && hash_equals($expected, (string) $request->header('X-Monitoring-Token')), 404);
        $readiness = $resilience->readiness();
        $consistency = $resilience->consistencyAudit();
        $values = [
            'task_mall_up' => 1,
            'task_mall_ready' => $readiness['status'] === 'ready' ? 1 : 0,
            'task_mall_business_consistency' => $consistency['healthy'] ? 1 : 0,
            'task_mall_failed_jobs' => $this->count('failed_jobs'),
            'task_mall_pending_jobs' => $this->count('jobs'),
            'task_mall_failed_notification_deliveries' => $this->count('notification_deliveries', ['status' => 'failed']),
            'task_mall_pending_refunds' => $this->countWhereIn('refunds', 'status', ['submitted', 'processing']),
        ];
        $body = collect($values)->map(fn (int $value, string $name) => "# TYPE {$name} gauge\n{$name} {$value}")->implode("\n")."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function count(string $table, array $where = []): int
    {
        return Schema::hasTable($table) ? DB::table($table)->where($where)->count() : 0;
    }

    private function countWhereIn(string $table, string $column, array $values): int
    {
        return Schema::hasTable($table) ? DB::table($table)->whereIn($column, $values)->count() : 0;
    }
}
