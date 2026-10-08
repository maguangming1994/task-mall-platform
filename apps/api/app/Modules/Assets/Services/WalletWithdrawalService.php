<?php

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Models\WalletPayout;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Wallet\Models\UserBankCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletWithdrawalService
{
    public function __construct(
        private readonly AssetLedgerService $ledger,
        private readonly SystemSettingService $settings,
        private readonly PayoutProvider $provider,
    ) {}

    public function create(User $user, int $amount, string $channel, string $idempotencyKey, ?UserBankCard $bankCard = null): WalletWithdrawal
    {
        if ($channel === 'bank_card' && $bankCard === null) {
            throw ValidationException::withMessages(['bank_card_id' => ['请选择提现银行卡。']]);
        }
        if ($bankCard !== null && $bankCard->user_id !== $user->id) {
            throw ValidationException::withMessages(['bank_card_id' => ['收款银行卡不存在或已解绑，请重新选择。']]);
        }
        $bankCardSnapshot = $bankCard !== null ? $this->bankCardSnapshot($bankCard) : null;
        $hash = $this->fingerprint('withdrawal:'.$user->id.':'.$idempotencyKey);
        $payloadHash = $this->requestPayloadFingerprint($amount, $channel, $bankCard?->public_id);

        return DB::transaction(function () use ($user, $amount, $channel, $hash, $payloadHash, $bankCardSnapshot): WalletWithdrawal {
            // Every withdrawal for a user serializes on the wallet account before
            // limits are read, so a concurrent request cannot pass stale totals.
            $this->lockedWalletAccount($user);
            // The wallet-account lock serializes all create attempts for this
            // user, so the unique-key lookup does not need to take a second
            // lock (which would invert the lock order used by review).
            $existing = WalletWithdrawal::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing !== null) {
                if (! $this->matchesRequestPayload($existing, $payloadHash)) {
                    throw new IdempotencyConflictException('相同幂等键已绑定不同的提现请求，请更换幂等键。');
                }

                return $existing->fresh();
            }

            $quote = $this->quote($amount);
            $usage = $this->dailyUsage($user);
            if ($usage['count'] >= $usage['count_limit'] || $usage['amount_limit'] < $usage['amount'] + $amount) {
                throw ValidationException::withMessages(['amount' => ['今日可提现次数或金额已达上限，请明天再试。']]);
            }

            $withdrawal = WalletWithdrawal::query()->create([
                'withdrawal_no' => 'WD'.now()->format('Ymd').Str::upper(Str::random(12)),
                'user_id' => $user->id,
                'requested_amount_cents' => $amount,
                'fee_rate_ppm' => $quote['fee_rate'],
                'fee_amount_cents' => $quote['fee_amount'],
                'received_amount_cents' => $quote['received_amount'],
                'channel' => $channel,
                'status' => 'reviewing',
                'channel_reference' => $bankCardSnapshot['id'] ?? null,
                'bank_card_snapshot' => $bankCardSnapshot,
                'idempotency_key_hash' => $hash,
                'request_payload_hash' => $payloadHash,
            ]);
            $this->ledger->freezeWallet($user, $amount, '提交提现申请并冻结余额。', 'withdrawal-freeze:'.$withdrawal->public_id);

            return $withdrawal->fresh();
        });
    }

    /**
     * 用户端提现规则视图：小程序进入提现页即拉取并完整公示，
     * 包含门槛、费率、每日次数/额度、当日剩余额度与审核到账说明。
     *
     * @return array<string,mixed>
     */
    public function rules(User $user): array
    {
        $minimum = (int) $this->settings->read('wallet.min_withdrawal_cents')['value'];
        $maximum = (int) $this->settings->read('wallet.max_withdrawal_cents')['value'];
        $ratePpm = (int) round((float) $this->settings->read('wallet.withdraw_fee_rate')['value'] * 1_000_000);
        $usage = $this->dailyUsage($user);

        return [
            'enabled' => (bool) $this->settings->read('wallet.withdrawal_enabled')['value'],
            'minimum_amount' => $minimum,
            'maximum_amount' => $maximum,
            'fee_rate' => $ratePpm,
            'daily_count_limit' => $usage['count_limit'],
            'daily_amount_limit' => $usage['amount_limit'],
            'today_count' => $usage['count'],
            'today_amount' => $usage['amount'],
            'daily_count_remaining' => max(0, $usage['count_limit'] - $usage['count']),
            'daily_amount_remaining' => max(0, $usage['amount_limit'] - $usage['amount']),
            // 当前所有提现均为平台人工审核后出款，流程文案必须与实际一致。
            'review_mode' => 'manual',
            'arrival_text' => '提交后平台尽快完成审核，预计 1-3 个工作日到账（周末及节假日顺延）。',
            'channels' => [
                ['code' => 'wechat_transfer', 'label' => '微信零钱', 'arrival_text' => '审核通过后转账至微信支付实名账户。'],
                ['code' => 'bank_card', 'label' => '银行卡', 'arrival_text' => '审核通过后由财务在工作日人工转账至本人储蓄卡。'],
            ],
        ];
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
            throw ValidationException::withMessages(['amount' => ["提现金额需在 {$this->yuan($minimum)} 元至 {$this->yuan($maximum)} 元之间。"]]);
        }
        $ratePpm = (int) round((float) $this->settings->read('wallet.withdraw_fee_rate')['value'] * 1_000_000);
        $fee = intdiv($amount * $ratePpm + 999_999, 1_000_000);
        if ($fee >= $amount) {
            // 极小额提现实算手续费不足 1 分，免收手续费，确保达到最低门槛的用户都能成功提现。
            $fee = 0;
        }

        return [
            'requested_amount' => $amount,
            'fee_rate' => $ratePpm,
            'fee_amount' => $fee,
            'received_amount' => $amount - $fee,
            'minimum_amount' => $minimum,
            'maximum_amount' => $maximum,
        ];
    }

    /** 当日有效提现（不含已拒绝/失败/取消）的笔数与金额及配置上限，金额单位均为分。 @return array{count:int,amount:int,count_limit:int,amount_limit:int} */
    private function dailyUsage(User $user): array
    {
        $today = CarbonImmutable::now('Asia/Shanghai')->startOfDay();
        $query = WalletWithdrawal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $today)
            ->whereNotIn('status', ['rejected', 'failed', 'cancelled']);

        return [
            'count' => (clone $query)->count(),
            'amount' => (int) (clone $query)->sum('requested_amount_cents'),
            'count_limit' => (int) $this->settings->read('wallet.daily_withdrawal_count')['value'],
            'amount_limit' => (int) $this->settings->read('wallet.daily_withdrawal_cents')['value'],
        ];
    }

    private function yuan(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function review(AdminUser $admin, WalletWithdrawal $withdrawal, string $decision, string $note, string $idempotencyKey): WalletWithdrawal
    {
        $hash = $this->fingerprint('withdrawal-review:'.$withdrawal->id.':'.$idempotencyKey);
        if (WalletWithdrawal::query()->where('review_idempotency_key_hash', $hash)->exists()) {
            return $withdrawal->fresh();
        }

        $result = DB::transaction(function () use ($admin, $withdrawal, $decision, $note, $hash): WalletWithdrawal {
            $locked = WalletWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($locked->status !== 'reviewing') {
                throw ValidationException::withMessages(['decision' => ['当前提现单不在待审核状态。']]);
            }
            $approved = $decision === 'approve';
            $locked->update([
                'status' => $approved ? 'processing' : 'rejected',
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note,
                'review_idempotency_key_hash' => $hash,
                'reviewed_at' => now(),
                'completed_at' => $approved ? null : now(),
            ]);
            if (! $approved) {
                $user = User::query()->findOrFail($locked->user_id);
                $this->ledger->unfreezeWallet($user, $locked->requested_amount_cents, '提现审核拒绝，解冻原申请金额。', 'withdrawal-unfreeze:'.$locked->public_id, $admin);
            }

            return $locked->fresh();
        });

        if ($result->status !== 'processing') {
            return $result;
        }

        // 银行卡通道由财务线下人工转账，不提交线上出款渠道，等待登记打款结果
        return $result->channel === 'bank_card' ? $result : $this->dispatch($result);
    }

    /**
     * 登记银行卡通道的人工打款结果（财务线下转账后调用）。
     */
    public function recordManualResult(AdminUser $admin, WalletWithdrawal $withdrawal, string $decision, string $reference, string $note): WalletWithdrawal
    {
        return DB::transaction(function () use ($admin, $withdrawal, $decision, $reference, $note): WalletWithdrawal {
            $locked = WalletWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            abort_unless($locked->channel === 'bank_card', 422, '仅银行卡提现可以登记人工打款结果。');
            if ($locked->status !== 'processing') {
                throw ValidationException::withMessages(['decision' => ['只有处理中的提现单可以登记打款结果。']]);
            }

            $user = User::query()->findOrFail($locked->user_id);
            $payout = WalletPayout::query()->firstOrCreate(
                ['wallet_withdrawal_id' => $locked->id],
                [
                    'payout_no' => $locked->withdrawal_no,
                    'provider' => 'manual_bank',
                    'amount_cents' => $locked->received_amount_cents,
                    'status' => 'pending',
                ],
            );

            if ($decision === 'paid') {
                $reference = trim($reference);
                abort_if($reference === '', 422, '请填写银行转账凭证号或流水号。');
                $this->ledger->consumeFrozenWallet($user, $locked->requested_amount_cents, '银行卡人工转账完成，扣减冻结余额。', 'withdrawal-success:'.$locked->public_id, $admin);
                $locked->update(['status' => 'succeeded', 'channel_reference' => $reference, 'failure_message' => null, 'completed_at' => now()]);
                $payout->update(['status' => 'succeeded', 'provider_reference' => $reference, 'last_error' => null, 'completed_at' => now()]);
            } else {
                $message = trim($note) !== '' ? mb_substr($note, 0, 500) : '银行卡人工打款失败，金额已解冻。';
                $this->ledger->unfreezeWallet($user, $locked->requested_amount_cents, '银行卡人工打款失败，解冻原申请金额。', 'withdrawal-failed:'.$locked->public_id, $admin);
                $locked->update(['status' => 'failed', 'failure_message' => $message, 'completed_at' => now()]);
                $payout->update(['status' => 'failed', 'last_error' => $message, 'completed_at' => now()]);
            }

            return $locked->fresh();
        });
    }

    public function dispatch(WalletWithdrawal $withdrawal): WalletWithdrawal
    {
        $withdrawal = WalletWithdrawal::query()->findOrFail($withdrawal->id);
        if ($withdrawal->status === 'succeeded') {
            return $withdrawal;
        }
        if ($withdrawal->channel === 'bank_card') {
            throw ValidationException::withMessages(['withdrawal' => '银行卡提现由财务人工转账，无需提交线上出款渠道。']);
        }
        if ($withdrawal->status !== 'processing') {
            throw ValidationException::withMessages(['withdrawal' => '只有处理中的提现单可以提交出款。']);
        }
        $payout = WalletPayout::query()->firstOrCreate(
            ['wallet_withdrawal_id' => $withdrawal->id],
            [
                'payout_no' => $withdrawal->withdrawal_no,
                'provider' => $this->provider->name(),
                'amount_cents' => $withdrawal->received_amount_cents,
                'status' => 'pending',
            ],
        );
        if (in_array($payout->status, ['succeeded', 'failed'], true)) {
            return $withdrawal->fresh();
        }

        try {
            $this->processProviderResult($this->provider->submit($withdrawal));
        } catch (\Throwable $exception) {
            $payout->update([
                'retry_count' => $payout->retry_count + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
            $this->recordProviderFailure($payout, 'submit', $exception);
            throw $exception;
        }

        return $withdrawal->fresh();
    }

    /** @param array<string,string|null> $headers */
    public function handleProviderNotification(array $headers, string $body): void
    {
        $this->processProviderResult($this->provider->parseNotification($headers, $body));
    }

    public function sync(WalletWithdrawal $withdrawal): WalletWithdrawal
    {
        if ($withdrawal->status === 'succeeded') {
            return $withdrawal->fresh();
        }
        if ($withdrawal->channel === 'bank_card') {
            throw ValidationException::withMessages(['withdrawal' => '银行卡提现由财务人工转账，结果请通过人工打款登记。']);
        }
        if ($withdrawal->status !== 'processing') {
            throw ValidationException::withMessages(['withdrawal' => '只有处理中的提现单可以主动查询。']);
        }
        $payout = WalletPayout::query()->where('wallet_withdrawal_id', $withdrawal->id)->first();
        if ($payout === null) {
            return $this->dispatch($withdrawal);
        }
        try {
            $this->processProviderResult($this->provider->query($withdrawal));
            $payout->update(['last_queried_at' => now()]);
        } catch (\Throwable $exception) {
            $payout->update([
                'retry_count' => $payout->retry_count + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
                'last_queried_at' => now(),
            ]);
            $this->recordProviderFailure($payout, 'query', $exception);
            throw $exception;
        }

        return $withdrawal->fresh();
    }

    public function syncPending(int $limit = 100): int
    {
        $count = 0;
        WalletWithdrawal::query()->where('status', 'processing')->where('channel', '!=', 'bank_card')->orderBy('id')->limit($limit)->get()
            ->each(function (WalletWithdrawal $withdrawal) use (&$count): void {
                try {
                    $this->sync($withdrawal);
                    $count++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

        return $count;
    }

    /** @param array<string,mixed> $result */
    public function processProviderResult(array $result): void
    {
        $eventId = trim((string) ($result['event_id'] ?? ''));
        if ($eventId === '') {
            throw ValidationException::withMessages(['payout' => '出款渠道结果缺少事件编号。']);
        }
        $withdrawal = WalletWithdrawal::query()->where('withdrawal_no', (string) ($result['withdrawal_no'] ?? ''))->first();
        if ($withdrawal === null) {
            throw ValidationException::withMessages(['payout' => '出款渠道结果对应的提现单不存在。']);
        }
        $payout = WalletPayout::query()->where('wallet_withdrawal_id', $withdrawal->id)->firstOrFail();
        $event = [
            'provider' => $this->provider->name(),
            'event_id' => $eventId,
            'wallet_payout_id' => $payout->id,
            'event_type' => (string) ($result['event_type'] ?? ''),
            'provider_state' => strtoupper((string) ($result['state'] ?? '')),
            'provider_reference' => $result['provider_reference'] ?? null,
            'resource_hash' => hash('sha256', json_encode($result['raw_resource'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'received',
            'occurred_at' => $result['occurred_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (DB::table('wallet_payout_events')->insertOrIgnore($event) === 0) {
            $existing = DB::table('wallet_payout_events')->where(['provider' => $this->provider->name(), 'event_id' => $eventId])->first();
            if ($existing?->status === 'processed') {
                return;
            }
            DB::table('wallet_payout_events')->where('id', $existing->id)->update(['status' => 'received', 'error_message' => null, 'updated_at' => now()]);
        }
        $eventDbId = DB::table('wallet_payout_events')->where(['provider' => $this->provider->name(), 'event_id' => $eventId])->value('id');

        try {
            $amount = (int) ($result['amount_cents'] ?? 0);
            if ($amount > 0 && $amount !== $withdrawal->received_amount_cents) {
                throw ValidationException::withMessages(['payout' => '出款渠道返回金额与提现实收金额不一致。']);
            }
            $this->applyProviderState($withdrawal, $payout, strtoupper((string) ($result['state'] ?? '')), trim((string) ($result['provider_reference'] ?? '')));
            DB::table('wallet_payout_events')->where('id', $eventDbId)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('wallet_payout_events')->where('id', $eventDbId)->update(['status' => 'rejected', 'error_message' => mb_substr($exception->getMessage(), 0, 500), 'processed_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function applyProviderState(WalletWithdrawal $withdrawal, WalletPayout $payout, string $state, string $reference): void
    {
        DB::transaction(function () use ($withdrawal, $payout, $state, $reference): void {
            $lockedWithdrawal = WalletWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            $lockedPayout = WalletPayout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($lockedPayout->provider_reference !== null && $reference !== '' && $lockedPayout->provider_reference !== $reference) {
                throw ValidationException::withMessages(['payout' => '出款渠道流水号与已记录结果冲突。']);
            }
            $providerReference = $reference !== '' ? $reference : $lockedPayout->provider_reference;
            if (in_array($state, ['PROCESSING', 'PENDING', 'ACCEPTED'], true)) {
                if ($lockedWithdrawal->status === 'processing') {
                    $lockedPayout->update(['status' => 'processing', 'provider_reference' => $providerReference, 'submitted_at' => $lockedPayout->submitted_at ?? now(), 'last_error' => null]);
                    $lockedWithdrawal->update(['channel_reference' => $providerReference]);
                }

                return;
            }
            if (in_array($state, ['SUCCESS', 'SUCCEEDED'], true)) {
                if ($lockedWithdrawal->status === 'succeeded') {
                    return;
                }
                if ($lockedWithdrawal->status !== 'processing') {
                    throw ValidationException::withMessages(['payout' => '非处理中的提现单不能变更为出款成功。']);
                }
                if ($providerReference === null || $providerReference === '') {
                    throw ValidationException::withMessages(['payout' => '出款成功结果缺少渠道流水号，已阻止本地扣账。']);
                }
                $user = User::query()->findOrFail($lockedWithdrawal->user_id);
                $admin = $lockedWithdrawal->reviewed_by_admin_id ? AdminUser::query()->find($lockedWithdrawal->reviewed_by_admin_id) : null;
                $this->ledger->consumeFrozenWallet($user, $lockedWithdrawal->requested_amount_cents, '提现渠道确认成功，扣减冻结余额。', 'withdrawal-success:'.$lockedWithdrawal->public_id, $admin);
                $lockedWithdrawal->update(['status' => 'succeeded', 'channel_reference' => $providerReference, 'failure_message' => null, 'completed_at' => now()]);
                $lockedPayout->update(['status' => 'succeeded', 'provider_reference' => $providerReference, 'last_error' => null, 'completed_at' => now()]);

                return;
            }
            if (in_array($state, ['FAILED', 'CLOSED', 'REJECTED'], true)) {
                if ($lockedWithdrawal->status === 'failed') {
                    return;
                }
                if ($lockedWithdrawal->status !== 'processing') {
                    throw ValidationException::withMessages(['payout' => '非处理中的提现单不能变更为出款失败。']);
                }
                $user = User::query()->findOrFail($lockedWithdrawal->user_id);
                $admin = $lockedWithdrawal->reviewed_by_admin_id ? AdminUser::query()->find($lockedWithdrawal->reviewed_by_admin_id) : null;
                $this->ledger->unfreezeWallet($user, $lockedWithdrawal->requested_amount_cents, '提现渠道终态失败，解冻原申请金额。', 'withdrawal-failed:'.$lockedWithdrawal->public_id, $admin);
                $message = '出款渠道返回终态失败：'.$state;
                $lockedWithdrawal->update(['status' => 'failed', 'channel_reference' => $providerReference, 'failure_message' => $message, 'completed_at' => now()]);
                $lockedPayout->update(['status' => 'failed', 'provider_reference' => $providerReference, 'last_error' => $message, 'completed_at' => now()]);

                return;
            }

            throw ValidationException::withMessages(['payout' => '出款渠道返回了无法识别的状态。']);
        });
    }

    /** @return array<string,mixed> */
    public function present(WalletWithdrawal $withdrawal): array
    {
        $payout = WalletPayout::query()->where('wallet_withdrawal_id', $withdrawal->id)->first();
        $isBankCard = $withdrawal->channel === 'bank_card';
        $workflow = $this->workflow($withdrawal->status, $isBankCard, is_array($withdrawal->bank_card_snapshot) ? ($withdrawal->bank_card_snapshot['card_tail'] ?? null) : null);

        return [
            'id' => $withdrawal->public_id,
            'withdrawal_no' => $withdrawal->withdrawal_no,
            'requested_amount' => $withdrawal->requested_amount_cents,
            'fee_rate' => $withdrawal->fee_rate_ppm,
            'fee_amount' => $withdrawal->fee_amount_cents,
            'received_amount' => $withdrawal->received_amount_cents,
            'channel' => $withdrawal->channel,
            'channel_label' => $isBankCard ? '银行卡' : '微信零钱',
            'status' => $withdrawal->status,
            'workflow' => $workflow,
            'review_note' => $withdrawal->review_note,
            'channel_reference' => $withdrawal->channel_reference,
            'bank_card' => $isBankCard ? $withdrawal->bank_card_snapshot : null,
            'failure_message' => $withdrawal->failure_message,
            'payout' => $payout ? ['provider' => $payout->provider, 'status' => $payout->status, 'retry_count' => $payout->retry_count, 'last_queried_at' => $payout->last_queried_at?->toIso8601String()] : null,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
            'completed_at' => $withdrawal->completed_at?->toIso8601String(),
        ];
    }

    /** @return array{status_label:string,next_action:string,is_terminal:bool} */
    private function workflow(string $status, bool $isBankCard = false, ?string $cardTail = null): array
    {
        $processingAction = $isBankCard
            ? '审核已通过，财务将在工作日向'.($cardTail ? '尾号 '.$cardTail.' ' : '').'银行卡转账，请留意到账通知。'
            : '申请已通过，出款渠道正在处理，无需重复提交。';
        $succeededAction = $isBankCard ? '提现已完成，可在收款银行账户核对到账记录。' : '提现已完成，可在微信钱包或收款渠道核对到账记录。';

        return match ($status) {
            'reviewing', 'submitted' => ['status_label' => '审核中', 'next_action' => '平台正在核对提现资格和金额，请留意审核结果。', 'is_terminal' => false],
            'approved', 'processing' => ['status_label' => '打款处理中', 'next_action' => $processingAction, 'is_terminal' => false],
            'succeeded', 'completed' => ['status_label' => '已到账', 'next_action' => $succeededAction, 'is_terminal' => true],
            'rejected' => ['status_label' => '审核未通过', 'next_action' => '原申请金额已解冻，请查看审核说明后重新提交。', 'is_terminal' => true],
            'failed' => ['status_label' => '打款失败', 'next_action' => '原申请金额已解冻；如仍需提现，请确认收款信息后重新提交。', 'is_terminal' => true],
            'cancelled' => ['status_label' => '已取消', 'next_action' => '原申请金额已解冻，可重新发起提现。', 'is_terminal' => true],
            default => ['status_label' => '状态同步中', 'next_action' => '请稍后刷新，若长时间未更新可联系校园客服。', 'is_terminal' => false],
        };
    }

    /** @return array{id:string,cardholder_name:string,bank_name:string,card_tail:string,card_masked:string,phone_masked:string} */
    private function bankCardSnapshot(UserBankCard $card): array
    {
        return [
            'id' => $card->public_id,
            'cardholder_name' => $card->cardholder_name,
            'bank_name' => $card->bank_name,
            'card_tail' => $card->card_tail,
            'card_masked' => '**** **** **** '.$card->card_tail,
            'phone_masked' => $card->phone_masked,
        ];
    }

    private function recordProviderFailure(WalletPayout $payout, string $operation, \Throwable $exception): void
    {
        DB::table('wallet_payout_events')->insertOrIgnore([
            'provider' => $this->provider->name(),
            'event_id' => "{$operation}-error-{$payout->payout_no}-{$payout->retry_count}",
            'wallet_payout_id' => $payout->id,
            'event_type' => 'PAYOUT.'.strtoupper($operation).'.ERROR',
            'provider_state' => null,
            'provider_reference' => null,
            'resource_hash' => hash('sha256', get_class($exception).':'.$exception->getCode()),
            'status' => 'rejected',
            'error_message' => mb_substr($exception->getMessage(), 0, 500),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lockedWalletAccount(User $user): UserAssetAccount
    {
        // Locking the user row also serializes first-time account creation; the
        // account row is then locked for the remainder of the withdrawal tx.
        User::query()->lockForUpdate()->findOrFail($user->id);
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);

        return UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function requestPayloadFingerprint(int $amount, string $channel, ?string $bankCardId = null): string
    {
        $payload = [
            'amount' => $amount,
            'channel' => mb_strtolower(trim($channel), 'UTF-8'),
            'bank_card_id' => $bankCardId,
        ];

        return $this->fingerprint('withdrawal-payload:'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function matchesRequestPayload(WalletWithdrawal $withdrawal, string $payloadHash): bool
    {
        $storedHash = $withdrawal->request_payload_hash;
        if (! is_string($storedHash) || $storedHash === '') {
            // Rows created before the additive migration have no stored hash;
            // derive it from their immutable request fields for compatibility.
            $storedHash = $this->requestPayloadFingerprint(
                (int) $withdrawal->requested_amount_cents,
                (string) $withdrawal->channel,
            );
        }

        return hash_equals($storedHash, $payloadHash);
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
