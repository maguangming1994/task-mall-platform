<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointDebt;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetLedgerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_use_source_expiry_fifo_allocations_and_new_grants_repay_debt_first(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $user = User::factory()->create();
        $ledger = app(AssetLedgerService::class);
        $ledger->grantPoints($user, 100, 'community_post', 'post-1', '发帖奖励', 'post-grant-1');
        $ledger->grantPoints($user, 50, 'community_comment', 'comment-1', '评论奖励', 'comment-grant-1');
        $account = $ledger->debitPoints($user, 80, '测试按到期顺序消费。', 'spend-1', null, 'spend');
        $this->assertSame(70, $account->points_available);
        $comment = PointBatch::query()->where('source_code', 'community_comment')->firstOrFail();
        $post = PointBatch::query()->where('source_code', 'community_post')->firstOrFail();
        $this->assertSame(0, $comment->remaining_points);
        $this->assertSame(70, $post->remaining_points);
        $this->assertDatabaseCount('point_batch_allocations', 2);
        $post->update(['expires_at' => now()->subMinute()]);
        $this->artisan('assets:expire')->assertSuccessful();
        $this->artisan('assets:expire')->assertSuccessful();
        $this->assertSame(0, $account->fresh()->points_available);

        $debtor = User::factory()->create();
        $debtorAccount = UserAssetAccount::query()->create(['user_id' => $debtor->id, 'points_debt' => 40]);
        PointDebt::query()->create([
            'user_id' => $debtor->id, 'source_code' => 'community_post', 'original_debt_points' => 40,
            'remaining_debt_points' => 40, 'reason' => '违规内容撤销。', 'event_key_hash' => hash('sha256', 'debt-1'),
        ]);
        $repaid = $ledger->grantPoints($debtor, 100, 'task_reward', 'task-1', '任务奖励先偿债。', 'task-grant-1');
        $this->assertSame(60, $repaid->points_available);
        $this->assertSame(0, $repaid->points_debt);
        $this->assertSame('repaid', PointDebt::query()->where('user_id', $debtor->id)->value('status'));
        $this->assertSame(1, PointBatch::query()->where('user_id', $debtor->id)->count());
        $this->assertSame(2, $debtorAccount->fresh()->version);
    }

    public function test_point_expiry_account_and_batch_schedule_are_visible_to_the_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$token, $user] = $this->phoneLoginWithAccountConsent('13800138991');
        $ledger = app(AssetLedgerService::class);
        $ledger->grantPoints($user, 30, 'signin', 'signin-1', '签到积分。', 'expiry-signin-1');
        $ledger->grantPoints($user, 80, 'task_reward', 'task-1', '任务奖励。', 'expiry-task-1');
        PointBatch::query()->where('user_id', $user->id)->where('source_code', 'signin')->update(['expires_at' => now()->addDays(5)]);
        PointBatch::query()->where('user_id', $user->id)->where('source_code', 'task_reward')->update(['expires_at' => now()->addDays(20)]);

        $this->withToken($token)->getJson('/api/v1/points/account')->assertOk()
            ->assertJsonPath('data.expiring_7_days', 30)
            ->assertJsonPath('data.expiring_30_days', 110);
        $this->withToken($token)->getJson('/api/v1/points/batches')->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.source_code', 'signin')
            ->assertJsonPath('data.items.0.remaining_points', 30);
    }

    public function test_wallet_adjustment_threshold_requires_four_eyes_and_is_idempotent(): void
    {
        $publisherToken = $this->adminToken('asset-publisher');
        $user = User::factory()->create();
        $url = '/api/v1/admin/users/'.$user->public_id.'/asset-adjustments';
        $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'wallet-adjust-low-0001'])->postJson($url, [
            'asset_type' => 'wallet', 'action' => 'increase', 'amount' => 20000, 'reason' => '低于二审门槛的补发。',
        ])->assertCreated()->assertJsonPath('data.status', 'applied');
        $headers = ['Idempotency-Key' => 'wallet-adjust-high-001'];
        $pending = $this->withToken($publisherToken)->withHeaders($headers)->postJson($url, [
            'asset_type' => 'wallet', 'action' => 'increase', 'amount' => 20001, 'reason' => '超过二审门槛的补发。',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $adjustmentId = $pending->json('data.id');
        $this->withToken($publisherToken)->withHeaders($headers)->postJson($url, [
            'asset_type' => 'wallet', 'action' => 'increase', 'amount' => 20001, 'reason' => '重复请求。',
        ])->assertCreated()->assertJsonPath('data.id', $adjustmentId);
        $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'wallet-self-review-01'])->postJson('/api/v1/admin/asset-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'approve', 'note' => '申请人自审。',
        ])->assertUnprocessable();
        $reviewerToken = $this->adminToken('asset-reviewer');
        $reviewHeaders = ['Idempotency-Key' => 'wallet-review-approve-1'];
        $this->withToken($reviewerToken)->withHeaders($reviewHeaders)->postJson('/api/v1/admin/asset-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'approve', 'note' => '财务复核通过。',
        ])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->withToken($reviewerToken)->withHeaders($reviewHeaders)->postJson('/api/v1/admin/asset-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'approve', 'note' => '重复请求。',
        ])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->assertSame(40001, UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents'));
        $this->assertDatabaseCount('wallet_transactions', 2);
        $clear = $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'wallet-clear-request-01'])->postJson($url, [
            'asset_type' => 'wallet', 'action' => 'clear', 'reason' => '按用户资产处置流程清零。',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->withToken($reviewerToken)->withHeaders(['Idempotency-Key' => 'wallet-clear-review-001'])->postJson('/api/v1/admin/asset-adjustments/'.$clear->json('data.id').'/review', [
            'decision' => 'approve', 'note' => '确认清零且保留流水。',
        ])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->assertSame(0, UserAssetAccount::query()->where('user_id', $user->id)->value('wallet_available_cents'));
        $this->assertDatabaseCount('wallet_transactions', 3);
        $transaction = WalletTransaction::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $transaction->update(['reason' => 'tampered']);
    }

    public function test_coupon_issue_uses_template_snapshot_and_expiry_updates_summary_idempotently(): void
    {
        $token = $this->adminToken('coupon-admin');
        $template = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-template-create-1'])->postJson('/api/v1/admin/coupon-templates', [
            'name' => '新人组合券', 'cash_discount_cents' => 500, 'points_discount' => 100,
            'minimum_cash_cents' => 1000, 'minimum_points' => 200, 'valid_days' => 30, 'total_quantity' => 100,
        ])->assertCreated();
        $user = User::factory()->create();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-issue-user-001'])->postJson('/api/v1/admin/users/'.$user->public_id.'/asset-adjustments', [
            'asset_type' => 'coupon', 'action' => 'issue', 'amount' => 2,
            'coupon_template_id' => $template->json('data.id'), 'reason' => '发放新人组合券。',
        ])->assertCreated()->assertJsonPath('data.status', 'applied');
        $this->assertSame(2, UserAssetAccount::query()->where('user_id', $user->id)->value('coupon_available'));
        $this->assertDatabaseCount('user_coupons', 2);
        $this->assertDatabaseCount('coupon_transactions', 2);
        $couponId = UserCoupon::query()->where('user_id', $user->id)->value('public_id');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-revoke-user-01'])->postJson('/api/v1/admin/user-coupons/'.$couponId.'/revoke', [
            'reason' => '运营撤销一张未使用券。',
        ])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(1, UserAssetAccount::query()->where('user_id', $user->id)->value('coupon_available'));
        $this->travel(31)->days();
        $this->artisan('assets:expire')->assertSuccessful();
        $this->artisan('assets:expire')->assertSuccessful();
        $this->assertSame(0, UserAssetAccount::query()->where('user_id', $user->id)->value('coupon_available'));
        $this->assertDatabaseCount('coupon_transactions', 4);
    }

    public function test_withdrawal_snapshots_fee_freezes_full_amount_and_rejection_unfreezes_idempotently(): void
    {
        $token = $this->adminToken('withdrawal-reviewer');
        $user = User::factory()->create();
        $ledger = app(AssetLedgerService::class);
        $ledger->changeWallet($user, 10000, '初始化提现测试余额。', 'withdrawal-balance-1');
        $service = app(WalletWithdrawalService::class);
        $withdrawal = $service->create($user, 1000, 'wechat_transfer', 'withdrawal-create-001');
        $same = $service->create($user, 1000, 'wechat_transfer', 'withdrawal-create-001');
        $this->assertSame($withdrawal->public_id, $same->public_id);
        $this->assertSame(6000, $withdrawal->fee_rate_ppm);
        $this->assertSame(6, $withdrawal->fee_amount_cents);
        $this->assertSame(994, $withdrawal->received_amount_cents);
        $account = UserAssetAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(9000, $account->wallet_available_cents);
        $this->assertSame(1000, $account->wallet_frozen_cents);

        $headers = ['Idempotency-Key' => 'withdrawal-reject-0001'];
        $url = '/api/v1/admin/wallet-withdrawals/'.$withdrawal->public_id.'/review';
        $this->withToken($token)->withHeaders($headers)->postJson($url, ['decision' => 'reject', 'note' => '测试拒绝并解冻。'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($token)->withHeaders($headers)->postJson($url, ['decision' => 'reject', 'note' => '重复请求。'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $account->refresh();
        $this->assertSame(10000, $account->wallet_available_cents);
        $this->assertSame(0, $account->wallet_frozen_cents);
        $this->assertDatabaseCount('wallet_transactions', 3);
    }

    private function adminToken(string $account): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }

    /** @return array{0:string,1:User} */
    private function phoneLoginWithAccountConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'asset-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $user = User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail();
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->assertOk()->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => "asset-consent-{$phone}-{$index}", 'X-Client-Platform' => 'h5-browser'])
                ->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])
                ->assertCreated();
        }

        return [$token, $user];
    }
}
