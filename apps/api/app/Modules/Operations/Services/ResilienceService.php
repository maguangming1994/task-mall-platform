<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResilienceService
{
    public function readiness(): array
    {
        return Cache::remember('system:readiness:v1', (int) config('resilience.readiness_cache_ttl_seconds'), function (): array {
            $checks = [
                'database' => $this->probe(fn () => DB::select('select 1')),
                'cache' => $this->cacheProbe(),
                'storage' => $this->probe(fn () => is_writable(storage_path('framework')) ?: throw new \RuntimeException('Storage is not writable.')),
                'migrations' => $this->migrationProbe(),
            ];

            return ['status' => collect($checks)->every(fn (array $check) => $check['ready']) ? 'ready' : 'not_ready', 'checks' => $checks, 'checked_at' => now()->toIso8601String()];
        });
    }

    public function consistencyAudit(): array
    {
        $checks = [
            'inventory_reservations' => $this->countIfTable('inventories', fn () => DB::table('inventories')->whereColumn('reserved_quantity', '>', 'on_hand_quantity')->count()),
            'point_batches' => $this->countIfTable('point_batches', fn () => DB::table('point_batches')->whereRaw('remaining_points + frozen_points > issued_points')->count()),
            'order_refund_caps' => $this->countIfTable('orders', fn () => DB::table('orders')->whereRaw('refunded_wallet_amount > paid_wallet_amount OR refunded_external_amount > paid_external_amount OR refunded_points > paid_points')->count()),
            'item_refund_caps' => $this->countIfTable('order_items', fn () => DB::table('order_items')->whereColumn('refunded_quantity', '>', 'quantity')->orWhereColumn('refunded_cash_amount', '>', 'paid_cash_amount')->orWhereColumn('refunded_points', '>', 'paid_points')->count()),
            'stale_notification_deliveries' => $this->countIfTable('notification_deliveries', fn () => DB::table('notification_deliveries')->whereIn('status', ['pending', 'retrying'])->where('updated_at', '<', now()->subMinutes((int) config('resilience.pending_delivery_grace_minutes')))->count()),
        ];

        return ['healthy' => collect($checks)->every(fn (array $check) => $check['violations'] === 0), 'checks' => $checks, 'checked_at' => now()->toIso8601String()];
    }

    public function recoveryDrill(): array
    {
        $key = 'recovery-drill:'.bin2hex(random_bytes(8));
        $checks = [
            'database_transaction' => $this->probe(function (): void {
                DB::transaction(function (): void {
                    DB::select('select 1');
                });
            }),
            'cache_round_trip' => $this->probe(function () use ($key): void {
                Cache::put($key, 'ok', 30);
                throw_unless(Cache::get($key) === 'ok', new \RuntimeException('Cache read mismatch.'));
                Cache::forget($key);
            }),
            'storage_write' => $this->probe(function (): void {
                $path = storage_path('framework/recovery-drill-'.bin2hex(random_bytes(6)));
                throw_unless(file_put_contents($path, 'ok') === 2, new \RuntimeException('Storage write failed.'));
                throw_unless(file_get_contents($path) === 'ok', new \RuntimeException('Storage read mismatch.'));
                @unlink($path);
            }),
        ];

        return ['successful' => collect($checks)->every(fn (array $check) => $check['ready']), 'checks' => $checks, 'checked_at' => now()->toIso8601String()];
    }

    private function cacheProbe(): array
    {
        return $this->probe(function (): void {
            $key = 'readiness:'.bin2hex(random_bytes(6));
            Cache::put($key, 'ok', 10);
            throw_unless(Cache::get($key) === 'ok', new \RuntimeException('Cache read mismatch.'));
            Cache::forget($key);
        });
    }

    private function migrationProbe(): array
    {
        return $this->probe(function (): void {
            throw_unless(Schema::hasTable('migrations'), new \RuntimeException('Migration table is missing.'));
            throw_unless(Schema::hasTable('users'), new \RuntimeException('Core tables are missing.'));
        });
    }

    private function countIfTable(string $table, callable $callback): array
    {
        if (! Schema::hasTable($table)) {
            return ['violations' => 0, 'skipped' => true];
        }

        return ['violations' => (int) $callback(), 'skipped' => false];
    }

    private function probe(callable $callback): array
    {
        $started = hrtime(true);
        try {
            $callback();

            return ['ready' => true, 'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2)];
        } catch (Throwable) {
            return ['ready' => false, 'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2)];
        }
    }
}
