<?php

use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Notification\Services\NotificationBusinessEventService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Operations\Models\DeploymentRelease;
use App\Modules\Operations\Services\ProductionReadinessService;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Operations\Services\ResilienceService;
use App\Modules\Operations\Services\UatService;
use App\Modules\Privacy\Services\AccountCancellationService;
use App\Modules\Privacy\Services\DataDeletionService;
use App\Modules\Refund\Services\ExchangeService;
use App\Modules\Refund\Services\RefundService;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Services\TaskAppealService;
use App\Modules\Task\Services\TaskClaimService;
use App\Modules\Task\Services\TaskDefinitionService;
use App\Modules\Task\Services\TaskRewardDeliveryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('privacy:process-cancellations', function (AccountCancellationService $service) {
    $this->info('Processed cancellations: '.$service->processDue());
})->purpose('Recheck and execute account cancellations whose cooling-off period ended');

Artisan::command('privacy:replay-deletions', function (DataDeletionService $service) {
    $this->info('Replayed deletion manifests: '.$service->replayManifests());
})->purpose('Reapply effective deletion manifests after restore or recovery');

Artisan::command('production:readiness {--json} {--strict}', function (ProductionReadinessService $service) {
    $result = $service->evaluate();
    if ($this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    } else {
        $this->table(['检查项', '状态', '待办'], array_map(fn (array $item) => [
            $item['label'], $item['ready'] ? '通过' : '未通过', $item['action'] ?? '-',
        ], $result['checks']));
        $this->info("通过 {$result['summary']['passed']}/{$result['summary']['total']}");
    }
    if ($this->option('strict') && ! $result['ready']) {
        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Evaluate production credentials, providers, security and compliance readiness without exposing secrets');

Artisan::command('tasks:activate-scheduled', function (TaskDefinitionService $service) {
    $this->info('Activated scheduled tasks: '.$service->activateScheduled());
})->purpose('Publish task versions whose configured start time has arrived');

Artisan::command('tasks:expire-claims', function (TaskClaimService $service) {
    $this->info('Expired task claims: '.$service->expireDue());
})->purpose('Expire unfinished task claims and release their reward reservations');

Artisan::command('tasks:remind-overdue-reviews', function () {
    $count = TaskSubmission::query()->whereIn('status', ['submitted', 'reviewing'])
        ->where('review_due_at', '<=', now())->whereNull('review_reminded_at')
        ->update(['review_reminded_at' => now(), 'updated_at' => now()]);
    $this->info('Marked overdue task review reminders: '.$count);
})->purpose('Mark overdue task reviews for reminder without automatically approving them');

Artisan::command('tasks:retry-reward-deliveries {--limit=100}', function (TaskRewardDeliveryService $service) {
    $this->info('Retried task reward deliveries: '.$service->retryDue((int) $this->option('limit')));
})->purpose('Retry independently failed task reward components');

Artisan::command('tasks:expire-appeal-windows', function (TaskAppealService $service) {
    $this->info('Finalized rejected claims after appeal window: '.$service->expireWindows());
})->purpose('Release task slots and reward reservations after the appeal deadline');

Artisan::command('after-sales:close-expired {--limit=100}', function (RefundService $refunds, ExchangeService $exchanges) {
    $limit = (int) $this->option('limit');
    $this->info('Closed expired refunds: '.$refunds->closeExpiredReturns($limit));
    $this->info('Closed expired exchanges: '.$exchanges->closeExpired($limit));
})->purpose('Close return and exchange requests whose user logistics deadline expired');

Artisan::command('refunds:sync-provider {--limit=100}', function (RefundService $service) {
    $this->info('Synced provider refunds: '.$service->syncPendingProviderRefunds((int) $this->option('limit')));
})->purpose('Query processing WeChat refunds and apply authoritative provider states');

Artisan::command('withdrawals:sync-payouts {--limit=100}', function (WalletWithdrawalService $service) {
    $this->info('Synced wallet payouts: '.$service->syncPending((int) $this->option('limit')));
})->purpose('Query processing wallet payouts and apply authoritative provider states');

Artisan::command('notifications:deliver {--limit=100}', function (NotificationService $service) {
    $this->info('Delivered notifications: '.$service->deliverPending((int) $this->option('limit')));
})->purpose('Deliver due external notification channel jobs with retry policy');

Artisan::command('notifications:dispatch-business-events {--limit=100}', function (NotificationBusinessEventService $service) {
    $this->info('Dispatched business notification events: '.$service->processPending((int) $this->option('limit')));
})->purpose('Turn committed task, order and post state events into configured notifications');

Artisan::command('notifications:run-campaigns {--limit=10}', function (NotificationService $service) {
    $this->info('Processed campaigns: '.$service->runCampaigns((int) $this->option('limit')));
})->purpose('Generate subscribed marketing notifications for due campaigns');

Artisan::command('system:consistency-audit {--json} {--strict}', function (ResilienceService $service) {
    $result = $service->consistencyAudit();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    return $this->option('strict') && ! $result['healthy'] ? self::FAILURE : self::SUCCESS;
})->purpose('Audit inventory, asset, refund and notification invariants without mutating business data');

Artisan::command('system:recovery-drill {--strict}', function (ResilienceService $service) {
    $result = $service->recoveryDrill();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    return $this->option('strict') && ! $result['successful'] ? self::FAILURE : self::SUCCESS;
})->purpose('Run reversible database, cache and storage recovery probes');

Artisan::command('uat:run {--release=local} {--automated-suite=passed} {--external-channels} {--real-device} {--json} {--strict}', function (UatService $service) {
    $run = $service->run((string) $this->option('release'), (string) $this->option('automated-suite'), (bool) $this->option('external-channels'), (bool) $this->option('real-device'));
    $result = ['id' => $run->public_id, 'release_version' => $run->release_version, 'status' => $run->status, 'summary' => $run->summary, 'report_hash' => $run->report_hash, 'cases' => $run->cases->map->only(['case_code', 'scope', 'status', 'evidence'])->all()];
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    return $this->option('strict') && $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
})->purpose('Record an immutable local or real-channel UAT evidence report');

Artisan::command('release:register {version} {commit_sha} {artifact_hash} {configuration_hash}', function (ReleaseControlService $service) {
    $release = $service->registerRelease((string) $this->argument('version'), (string) $this->argument('commit_sha'), (string) $this->argument('artifact_hash'), (string) $this->argument('configuration_hash'));
    $this->line(json_encode(['id' => $release->public_id, 'version' => $release->release_version, 'status' => $release->status], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
})->purpose('Register immutable release, artifact and configuration digests before deployment');

Artisan::command('release:transition {release} {action} {--percentage=0} {--reason=} {--evidence-hash=}', function (ReleaseControlService $service) {
    $release = DeploymentRelease::query()->where('public_id', $this->argument('release'))->orWhere('release_version', $this->argument('release'))->firstOrFail();
    $updated = $service->transition($release, (string) $this->argument('action'), (int) $this->option('percentage'), (string) $this->option('reason'), (string) $this->option('evidence-hash'));
    $this->line(json_encode(['id' => $updated->public_id, 'status' => $updated->status, 'rollout_percentage' => $updated->rollout_percentage], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
})->purpose('Record canary, completion or rollback after an authorized external deployment action');

Schedule::command('privacy:process-cancellations')->hourly()->withoutOverlapping();
Schedule::command('privacy:replay-deletions')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('tasks:activate-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('assets:expire')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('tasks:expire-claims')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tasks:remind-overdue-reviews')->everyTenMinutes()->withoutOverlapping();
Schedule::command('tasks:retry-reward-deliveries')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tasks:expire-appeal-windows')->hourly()->withoutOverlapping();
Schedule::command('payments:sync-pending --limit=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('orders:close-expired-payments --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('growth:scan-first-cash-orders --limit=500')->everyTenMinutes()->withoutOverlapping();
Schedule::command('after-sales:close-expired --limit=500')->hourly()->withoutOverlapping();
Schedule::command('refunds:sync-provider --limit=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('withdrawals:sync-payouts --limit=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('notifications:dispatch-business-events --limit=500')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:deliver --limit=500')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:run-campaigns --limit=20')->everyMinute()->withoutOverlapping();
Schedule::command('system:consistency-audit --strict')->dailyAt('04:10')->withoutOverlapping();
