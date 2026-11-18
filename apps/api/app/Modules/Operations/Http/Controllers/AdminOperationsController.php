<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notification\Models\NotificationCampaign;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Notification\Services\NotificationSceneConfigService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Operations\Models\DeploymentRelease;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Models\UatRun;
use App\Modules\Operations\Services\OperationsDashboardService;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Operations\Services\ResilienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOperationsController extends Controller
{
    public function dashboard(OperationsDashboardService $service): JsonResponse
    {
        return ApiResponse::success($service->metrics());
    }

    public function analytics(OperationsDashboardService $service): JsonResponse
    {
        return ApiResponse::success($service->analytics());
    }

    public function resilience(ResilienceService $service): JsonResponse
    {
        return ApiResponse::success(['readiness' => $service->readiness(), 'consistency' => $service->consistencyAudit()]);
    }

    public function uatRuns(): JsonResponse
    {
        $items = UatRun::query()->with('cases')->latest()->limit(50)->get()->map(fn (UatRun $run) => [
            'id' => $run->public_id,
            'release_version' => $run->release_version,
            'environment' => $run->environment,
            'status' => $run->status,
            'summary' => $run->summary,
            'report_hash' => $run->report_hash,
            'completed_at' => $run->completed_at->toIso8601String(),
            'cases' => $run->cases->map(fn ($case) => ['case_code' => $case->case_code, 'scope' => $case->scope, 'status' => $case->status, 'evidence' => $case->evidence]),
        ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function releaseControl(): JsonResponse
    {
        return ApiResponse::success([
            'feature_flags' => FeatureFlag::query()->orderBy('code')->get()->map(fn (FeatureFlag $flag) => $flag->only(['code', 'name', 'mode', 'rollout_percentage', 'audience', 'platform_modes', 'placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed', 'version', 'updated_at'])),
            'releases' => DeploymentRelease::query()->with('events')->latest()->limit(30)->get()->map(fn (DeploymentRelease $release) => ['id' => $release->public_id, 'release_version' => $release->release_version, 'environment' => $release->environment, 'commit_sha' => $release->commit_sha, 'artifact_hash' => $release->artifact_hash, 'configuration_hash' => $release->configuration_hash, 'status' => $release->status, 'rollout_percentage' => $release->rollout_percentage, 'deployed_at' => $release->deployed_at?->toIso8601String(), 'rolled_back_at' => $release->rolled_back_at?->toIso8601String(), 'events' => $release->events->map->only(['event_type', 'from_status', 'to_status', 'rollout_percentage', 'reason', 'evidence_hash', 'created_at'])]),
        ]);
    }

    public function updateFeatureFlag(Request $request, FeatureFlag $flag, ReleaseControlService $service): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:disabled,percentage,enabled'], 'rollout_percentage' => ['required', 'integer', 'min:0', 'max:100'], 'audience' => ['nullable', 'array'], 'audience.platforms' => ['nullable', 'array'], 'audience.platforms.*' => ['in:wechat-mini,h5-wechat,h5-browser'], 'audience.subject_allowlist' => ['nullable', 'array', 'max:100'], 'audience.subject_allowlist.*' => ['string', 'max:80'], 'platform_modes' => ['nullable', 'array'], 'platform_modes.wechat-mini' => ['nullable', 'in:inherit,enabled,disabled'], 'platform_modes.h5' => ['nullable', 'in:inherit,enabled,disabled'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'placeholder' => ['nullable', 'array'], 'placeholder.title_reviewing' => ['nullable', 'string', 'max:160'], 'placeholder.desc_reviewing' => ['nullable', 'string', 'max:2000'], 'placeholder.title_reviewed' => ['nullable', 'string', 'max:160'], 'placeholder.desc_reviewed' => ['nullable', 'string', 'max:2000']]); /** @var AdminUser $admin */
        $admin = $request->user();
        $placeholder = isset($data['placeholder']) ? [
            'title_reviewing' => $data['placeholder']['title_reviewing'] ?? null,
            'desc_reviewing' => $data['placeholder']['desc_reviewing'] ?? null,
            'title_reviewed' => $data['placeholder']['title_reviewed'] ?? null,
            'desc_reviewed' => $data['placeholder']['desc_reviewed'] ?? null,
        ] : null;
        $updated = $service->updateFlag($admin, $flag, $data['mode'], $data['rollout_percentage'], $data['audience'] ?? [], $data['expected_version'], $data['reason'], $placeholder, $data['platform_modes'] ?? null);

        return ApiResponse::success($updated->only(['code', 'name', 'mode', 'rollout_percentage', 'audience', 'platform_modes', 'placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed', 'version', 'updated_at']));
    }

    public function transitionRelease(Request $request, DeploymentRelease $release, ReleaseControlService $service): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:canary,complete,rollback'], 'rollout_percentage' => ['nullable', 'integer', 'min:0', 'max:100'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'evidence_hash' => ['required', 'regex:/^[a-f0-9]{64}$/']]); /** @var AdminUser $admin */
        $admin = $request->user();
        $updated = $service->transition($release, $data['action'], (int) ($data['rollout_percentage'] ?? 0), $data['reason'], $data['evidence_hash'], $admin);

        return ApiResponse::success(['id' => $updated->public_id, 'status' => $updated->status, 'rollout_percentage' => $updated->rollout_percentage]);
    }

    public function templates(): JsonResponse
    {
        return ApiResponse::success(['items' => NotificationTemplate::query()->latest()->get()->map(fn (NotificationTemplate $template) => $this->templateData($template))]);
    }

    public function notificationRules(NotificationSceneConfigService $service): JsonResponse
    {
        return ApiResponse::success(['items' => $service->catalog()]);
    }

    public function updateNotificationRule(Request $request, string $sceneCode, NotificationSceneConfigService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'in:in_app,wechat_mini,wechat_oa,sms'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $before = collect($service->catalog())->firstWhere('scene_code', $sceneCode);
        abort_if($before === null, 404);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $after = $service->update($sceneCode, $data, $admin);
        $audit->record($admin, 'notification_scene_rule.update', 'notification_scene_rule', $sceneCode, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function template(NotificationTemplate $notificationTemplate): JsonResponse
    {
        return ApiResponse::success($this->templateData($notificationTemplate, true));
    }

    public function saveTemplate(Request $request, NotificationService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['scene_code' => ['required', 'string', 'max:64'], 'channel' => ['required', 'in:in_app,wechat,wechat_mini,wechat_oa,sms'], 'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'allowed_variables' => ['present', 'array', 'max:20'], 'allowed_variables.*' => ['string', 'regex:/^[a-zA-Z0-9_]+$/'], 'provider_template_id' => ['nullable', 'string', 'max:160'], 'publish' => ['required', 'boolean']]); /** @var AdminUser $admin */
        $admin = $request->user();
        $existing = NotificationTemplate::query()->where('scene_code', $data['scene_code'])->where('channel', $data['channel'])->where('locale', 'zh-CN')->first();
        $before = $existing ? $this->templateData($existing, true) : null;
        $template = $service->saveTemplate($admin, $data);
        $after = $this->templateData($template, true);
        $audit->record($admin, $data['publish'] ? 'notification_template.version.publish' : 'notification_template.version.create', 'notification_template', $template->public_id, $before, $after);

        return ApiResponse::success($after, 201);
    }

    public function campaigns(): JsonResponse
    {
        return ApiResponse::success(['items' => NotificationCampaign::query()->latest()->get()->map(fn (NotificationCampaign $campaign) => $this->campaignData($campaign))]);
    }

    public function createCampaign(Request $request, NotificationService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:in_app,wechat,sms'], 'scheduled_at' => ['nullable', 'date']]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422); /** @var AdminUser $admin */
        $admin = $request->user();
        $existing = NotificationCampaign::query()->where('idempotency_key_hash', hash('sha256', $key))->first();
        $campaign = $service->createCampaign($admin, $data, $key);
        $after = $this->campaignData($campaign);
        if (! $existing) {
            $audit->record($admin, 'notification_campaign.create', 'notification_campaign', $campaign->public_id, null, $after);
        }

        return ApiResponse::success($after, 201);
    }

    public function cancelCampaign(Request $request, NotificationCampaign $notificationCampaign, NotificationService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->campaignData($notificationCampaign);
        $campaign = $service->cancelCampaign($notificationCampaign);
        $after = $this->campaignData($campaign);
        if ($before['status'] !== $after['status']) {
            $audit->record($admin, 'notification_campaign.cancel', 'notification_campaign', $campaign->public_id, $before, $after, $data['reason']);
        }

        return ApiResponse::success($after);
    }

    public function deliveries(): JsonResponse
    {
        $items = DB::table('notification_deliveries as deliveries')
            ->join('notifications', 'notifications.id', '=', 'deliveries.notification_id')
            ->whereIn('deliveries.status', ['retrying', 'failed'])
            ->latest('deliveries.id')
            ->limit(100)
            ->get([
                'deliveries.id', 'notifications.public_id as notification_id', 'notifications.title', 'notifications.scene_code',
                'deliveries.channel', 'deliveries.status', 'deliveries.retry_count', 'deliveries.next_retry_at',
                'deliveries.error_code', 'deliveries.error_message', 'deliveries.updated_at',
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function retryDelivery(Request $request, int $delivery, NotificationService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $before = DB::table('notification_deliveries')->find($delivery);
        abort_if($before === null, 404);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $after = $service->retryDelivery($delivery);
        if ($before->status !== $after->status) {
            $audit->record($admin, 'notification_delivery.retry', 'notification_delivery', (string) $delivery, (array) $before, (array) $after, $data['reason']);
        }

        return ApiResponse::success($after);
    }

    private function templateData(NotificationTemplate $template, bool $includeVersions = false): array
    {
        $versions = DB::table('notification_template_versions')
            ->where('notification_template_id', $template->id)
            ->orderByDesc('version_no')
            ->get()
            ->map(fn ($version) => [
                'version_no' => (int) $version->version_no,
                'subject' => $version->subject,
                'body' => $version->body,
                'allowed_variables' => json_decode($version->allowed_variables, true) ?: [],
                'provider_template_id' => $version->provider_template_id,
                'status' => $version->status,
                'published_at' => $version->published_at,
                'created_at' => $version->created_at,
            ]);
        $current = $versions->firstWhere('version_no', $template->current_version_no);
        $data = [
            'id' => $template->public_id,
            'scene_code' => $template->scene_code,
            'channel' => $template->channel,
            'status' => $template->status,
            'current_version_no' => $template->current_version_no,
            'current_version' => $current,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
        if ($includeVersions) {
            $data['versions'] = $versions->values();
        }

        return $data;
    }

    private function campaignData(NotificationCampaign $campaign): array
    {
        return [
            'id' => $campaign->public_id,
            'name' => $campaign->name,
            'title' => $campaign->title,
            'body' => $campaign->body,
            'channels' => $campaign->channels,
            'status' => $campaign->status,
            'recipient_count' => $campaign->recipient_count,
            'sent_count' => $campaign->sent_count,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
