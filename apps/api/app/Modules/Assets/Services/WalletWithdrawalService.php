<?php

namespace App\Modules\Assets\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletWithdrawalService
{
    public function __construct(private readonly AssetLedgerService $ledger, private readonly SystemSettingService $settings) {}

    public function create(User $user, int $amount, string $channel, string $idempotencyKey): WalletWithdrawal
    {
        $quote = $this->quote($amount);
        $hash = $this->fingerprint('withdrawal:'.$user->id.':'.$idempotencyKey);
        $existing = WalletWithdrawal::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $today = CarbonImmutable::now('Asia/Shanghai')->startOfDay();
        $daily = WalletWithdrawal::query()->where('user_id', $user->id)->where('created_at', '>=', $today)->whereNotIn('status', ['rejected', 'failed', 'cancelled']);
        $countLimit = (int) $this->settings->read('wallet.daily_withdrawal_count')['value'];
        $amountLimit = (int) $this->settings->read('wallet.daily_withdrawal_cents')['value'];
        if ((clone $daily)->count() >= $countLimit || (clone $daily)->sum('requested_amount_cents') + $amount > $amountLimit) {
            throw ValidationException::withMessages(['amount' => ['已超过当日提现次数或金额上限。']]);
        }
        $ratePpm = $quote['fee_rate'];
        $fee = $quote['fee_amount'];

        return DB::transaction(function () use ($user, $amount, $channel, $hash, $ratePpm, $fee): WalletWithdrawal {
            $withdrawal = WalletWithdrawal::query()->create([
                'withdrawal_no' => 'WD'.now()->format('Ymd').Str::upper(Str::random(12)), 'user_id' => $user->id,
                'requested_amount_cents' => $amount, 'fee_rate_ppm' => $ratePpm, 'fee_amount_cents' => $fee,
                'received_amount_cents' => $amount - $fee, 'channel' => $channel, 'status' => 'reviewing', 'idempotency_key_hash' => $hash,
            ]);
            $this->ledger->freezeWallet($user, $amount, '提交提现申请并冻结余额。', 'withdrawal-freeze:'.$withdrawal->public_id);

            return $withdrawal->fresh();
        });
    }

    /** @return array{requested_amount:int,fee_rate:int,fee_amount:int,received_amount:int,minimum_amount:int,maximum_amount:int} */
    public function quote(int $amount): array
    {
        if (! (bool) $this->settings->read('wallet.withdrawal_enabled')['value']) {
            throw ValidationException::withMessages(['amount' => ['提现功能当前未开放。']]);
        }
        $minimum = (int) $this->settings->read('wallet.min_withdrawal_cents')['value'];
        $maximum = (int) $this->settings->read('wallet.max_withdrawal_cents')['value'];
        if ($amount < $minimum || $amount > $maximum) {
            throw ValidationException::withMessages(['amount' => ["提现金额必须在 {$minimum} 至 {$maximum} 分之间。"]]);
        }
        $ratePpm = (int) round((float) $this->settings->read('wallet.withdraw_fee_rate')['value'] * 1_000_000);
        $fee = intdiv($amount * $ratePpm + 999_999, 1_000_000);
        if ($fee >= $amount) {
            throw ValidationException::withMessages(['amount' => ['手续费必须小于提现金额。']]);
        }

        return [
            'requested_amount' => $amount, 'fee_rate' => $ratePpm, 'fee_amount' => $fee,
            'received_amount' => $amount - $fee, 'minimum_amount' => $minimum, 'maximum_amount' => $maximum,
        ];
    }

    public function review(AdminUser $admin, WalletWithdrawal $withdrawal, string $decision, string $note, string $idempotencyKey): WalletWithdrawal
    {
        $hash = $this->fingerprint('withdrawal-review:'.$withdrawal->id.':'.$idempotencyKey);
        if (WalletWithdrawal::query()->where('review_idempotency_key_hash', $hash)->exists()) {
            return $withdrawal->fresh();
        }

        return DB::transaction(function () use ($admin, $withdrawal, $decision, $note, $hash): WalletWithdrawal {
            $locked = WalletWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($locked->status !== 'reviewing') {
                throw ValidationException::withMessages(['decision' => ['当前提现单不在待审核状态。']]);
            }
            $approved = $decision === 'approve';
            $locked->update([
                'status' => $approved ? 'processing' : 'rejected', 'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note, 'review_idempotency_key_hash' => $hash, 'reviewed_at' => now(),
                'completed_at' => $approved ? null : now(),
            ]);
            if (! $approved) {
                $user = User::query()->findOrFail($locked->user_id);
                $this->ledger->unfreezeWallet($user, $locked->requested_amount_cents, '提现审核拒绝，解冻原申请金额。', 'withdrawal-unfreeze:'.$locked->public_id, $admin);
            }

            return $locked->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function present(WalletWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->public_id, 'withdrawal_no' => $withdrawal->withdrawal_no,
            'requested_amount' => $withdrawal->requested_amount_cents, 'fee_rate' => $withdrawal->fee_rate_ppm,
            'fee_amount' => $withdrawal->fee_amount_cents, 'received_amount' => $withdrawal->received_amount_cents,
            'channel' => $withdrawal->channel, 'status' => $withdrawal->status, 'review_note' => $withdrawal->review_note,
            'failure_message' => $withdrawal->failure_message, 'created_at' => $withdrawal->created_at?->toIso8601String(),
            'completed_at' => $withdrawal->completed_at?->toIso8601String(),
        ];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
