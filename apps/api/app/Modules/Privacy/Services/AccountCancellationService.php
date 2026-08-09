<?php

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Models\AccountCancellationRequest;
use App\Modules\Privacy\Models\DataDeletionJob;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;

class AccountCancellationService
{
    public function __construct(private readonly AccountEligibilityService $eligibility, private readonly SystemSettingService $settings, private readonly DataDeletionService $deletions) {}

    /** @return array<string,mixed> */
    public function eligibility(User $user): array
    {
        return $this->eligibility->evaluate($user);
    }

    public function request(User $user, string $idempotencyKey): AccountCancellationRequest
    {
        $hash = $this->fingerprint($idempotencyKey);
        $existing = AccountCancellationRequest::query()->where('user_id', $user->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $active = AccountCancellationRequest::query()->where('user_id', $user->id)->where('status', 'cooling_off')->first();
        if ($active !== null) {
            throw new PrivacyException('ACCOUNT_CANCELLATION_STATE_INVALID', '已有冷静期中的注销申请。', 409);
        }
        $snapshot = $this->eligibility($user);
        if (! $snapshot['eligible']) {
            throw new PrivacyException('ACCOUNT_CANCELLATION_BLOCKED', '存在未结业务或受限资产，暂不能注销。', 409, ['eligibility' => $snapshot]);
        }
        $days = (int) $this->settings->read('accounts.cancellation_cooling_days')['value'];

        return AccountCancellationRequest::query()->create([
            'user_id' => $user->id, 'status' => 'cooling_off', 'eligibility_snapshot' => $snapshot,
            'forfeit_points_and_coupons' => true, 'idempotency_key_hash' => $hash, 'cooling_off_ends_at' => now()->addDays($days),
        ]);
    }

    public function withdraw(User $user, string $reason = 'user_withdrawn'): void
    {
        $request = AccountCancellationRequest::query()->where('user_id', $user->id)->where('status', 'cooling_off')->latest('id')->first();
        if ($request === null) {
            throw new PrivacyException('ACCOUNT_CANCELLATION_STATE_INVALID', '当前没有可撤销的注销申请。', 409);
        }
        $request->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
    }

    public function withdrawOnLogin(User $user): void
    {
        $request = AccountCancellationRequest::query()->where('user_id', $user->id)->where('status', 'cooling_off')->latest('id')->first();
        if ($request !== null) {
            $request->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'login_during_cooling_off']);
        }
    }

    public function processDue(): int
    {
        $processed = 0;
        AccountCancellationRequest::query()->where('status', 'cooling_off')->where('cooling_off_ends_at', '<=', now())->orderBy('id')->chunkById(100, function ($requests) use (&$processed): void {
            foreach ($requests as $request) {
                DB::transaction(function () use ($request, &$processed): void {
                    $locked = AccountCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);
                    if ($locked->status !== 'cooling_off') {
                        return;
                    }
                    $snapshot = $this->eligibility($locked->user);
                    if (! $snapshot['eligible']) {
                        $locked->update(['status' => 'rejected', 'eligibility_snapshot' => $snapshot]);

                        return;
                    }
                    $job = DataDeletionJob::query()->firstOrCreate(
                        ['idempotency_key' => hash('sha256', 'account-cancellation:'.$locked->id)],
                        ['user_id' => $locked->user_id, 'cancellation_request_id' => $locked->id, 'job_type' => 'account_cancellation', 'status' => 'running', 'run_after' => now()],
                    );
                    $this->deletions->executeCancellation($job);
                    $locked->update(['status' => 'executed', 'executed_at' => now()]);
                    $processed++;
                });
            }
        });

        return $processed;
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('auth_tokens.identity_hash_secret')));
    }
}
