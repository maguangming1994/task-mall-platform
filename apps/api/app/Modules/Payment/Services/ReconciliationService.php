<?php

namespace App\Modules\Payment\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Models\ReconciliationEntry;
use App\Modules\Payment\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function import(AdminUser $admin, string $date, array $rows, string $idempotencyKey): ReconciliationRun
    {
        $hash = hash('sha256', $idempotencyKey);
        if ($existing = ReconciliationRun::query()->where('idempotency_key_hash', $hash)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($admin, $date, $rows, $hash) {
            $day = CarbonImmutable::parse($date, 'Asia/Shanghai');
            $locals = Payment::query()->where('channel', 'wechat')->where('status', 'succeeded')->get()
                ->filter(fn (Payment $payment) => $payment->succeeded_at?->timezone('Asia/Shanghai')->toDateString() === $day->toDateString())
                ->keyBy('payment_no');
            $run = ReconciliationRun::query()->create(['provider' => 'wechat', 'business_date' => $day->toDateString(), 'source' => 'standard_import', 'status' => 'processing', 'idempotency_key_hash' => $hash, 'created_by_admin_id' => $admin->id]);
            $seen = [];
            $matched = 0;
            $differences = 0;
            foreach ($rows as $row) {
                $paymentNo = (string) $row['payment_no'];
                $seen[$paymentNo] = true;
                $local = $locals->get($paymentNo);
                $result = ! $local ? 'missing_local' : ((int) $row['cash_amount'] !== $local->cash_amount ? 'amount_mismatch' : (($row['state'] !== 'SUCCESS' || $local->status !== 'succeeded') ? 'state_mismatch' : 'matched'));
                $result === 'matched' ? $matched++ : $differences++;
                ReconciliationEntry::query()->create(['reconciliation_run_id' => $run->id, 'payment_id' => $local?->id, 'payment_no' => $paymentNo, 'provider_trade_no' => $row['provider_trade_no'] ?? null, 'provider_amount' => (int) $row['cash_amount'], 'local_amount' => $local?->cash_amount, 'provider_state' => $row['state'], 'local_state' => $local?->status, 'result' => $result, 'resolution_status' => $result === 'matched' ? 'not_required' : 'open', 'source_row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
            }
            foreach ($locals as $paymentNo => $local) {
                if (isset($seen[$paymentNo])) {
                    continue;
                }
                $differences++;
                ReconciliationEntry::query()->create(['reconciliation_run_id' => $run->id, 'payment_id' => $local->id, 'payment_no' => $paymentNo, 'provider_trade_no' => $local->provider_trade_no, 'provider_amount' => null, 'local_amount' => $local->cash_amount, 'provider_state' => null, 'local_state' => $local->status, 'result' => 'missing_provider', 'resolution_status' => 'open']);
            }
            $run->update(['status' => $differences > 0 ? 'differences_found' : 'matched', 'provider_count' => count($rows), 'provider_amount' => collect($rows)->sum('cash_amount'), 'local_count' => $locals->count(), 'local_amount' => $locals->sum('cash_amount'), 'matched_count' => $matched, 'difference_count' => $differences, 'completed_at' => now()]);

            return $run->fresh('entries');
        });
    }

    public function resolve(AdminUser $admin, ReconciliationEntry $entry, string $action, string $reason, string $idempotencyKey): ReconciliationEntry
    {
        return DB::transaction(function () use ($admin, $entry, $action, $reason, $idempotencyKey) {
            $locked = ReconciliationEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $hash = hash('sha256', $idempotencyKey);
            if (DB::table('reconciliation_actions')->where('idempotency_key_hash', $hash)->exists()) {
                return $locked;
            }
            abort_if($locked->result === 'matched', 422, 'Matched entry does not require resolution.');
            DB::table('reconciliation_actions')->insert(['reconciliation_entry_id' => $locked->id, 'admin_user_id' => $admin->id, 'action' => $action, 'reason' => $reason, 'snapshot' => json_encode($locked->toArray()), 'idempotency_key_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $locked->update(['resolution_status' => $action]);

            return $locked->fresh();
        });
    }

    public function present(ReconciliationRun $run): array
    {
        $run->loadMissing('entries');

        return ['id' => $run->public_id, 'provider' => $run->provider, 'business_date' => $run->business_date->toDateString(), 'status' => $run->status, 'provider_count' => $run->provider_count, 'provider_amount' => $run->provider_amount, 'local_count' => $run->local_count, 'local_amount' => $run->local_amount, 'matched_count' => $run->matched_count, 'difference_count' => $run->difference_count, 'entries' => $run->entries->map(fn ($entry) => ['id' => $entry->public_id, 'payment_no' => $entry->payment_no, 'provider_trade_no' => $entry->provider_trade_no, 'provider_amount' => $entry->provider_amount, 'local_amount' => $entry->local_amount, 'provider_state' => $entry->provider_state, 'local_state' => $entry->local_state, 'result' => $entry->result, 'resolution_status' => $entry->resolution_status]), 'completed_at' => $run->completed_at?->toIso8601String()];
    }
}
