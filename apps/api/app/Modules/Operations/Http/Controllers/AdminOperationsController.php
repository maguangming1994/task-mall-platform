<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Notification\Models\NotificationCampaign;
use App\Modules\Notification\Models\NotificationTemplate;
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
            'feature_flags' => FeatureFlag::query()->orderBy('code')->get()->map(fn (FeatureFlag $flag) => $flag->only(['code', 'name', 'mode', 'rollout_percentage', 'audience', 'version', 'updated_at'])),
            'releases' => DeploymentRelease::query()->with('events')->latest()->limit(30)->get()->map(fn (DeploymentRelease $release) => ['id' => $release->public_id, 'release_version' => $release->release_version, 'environment' => $release->environment, 'commit_sha' => $release->commit_sha, 'artifact_hash' => $release->artifact_hash, 'configuration_hash' => $release->configuration_hash, 'status' => $release->status, 'rollout_percentage' => $release->rollout_percentage, 'deployed_at' => $release->deployed_at?->toIso8601String(), 'rolled_back_at' => $release->rolled_back_at?->toIso8601String(), 'events' => $release->events->map->only(['event_type', 'from_status', 'to_status', 'rollout_percentage', 'reason', 'evidence_hash', 'created_at'])]),
        ]);
    }

    public function updateFeatureFlag(Request $request, FeatureFlag $flag, ReleaseControlService $service): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:disabled,percentage,enabled'], 'rollout_percentage' => ['required', 'integer', 'min:0', 'max:100'], 'audience' => ['nullable', 'array'], 'audience.platforms' => ['nullable', 'array'], 'audience.platforms.*' => ['in:wechat-mini,h5-wechat,h5-browser'], 'audience.subject_allowlist' => ['nullable', 'array', 'max:100'], 'audience.subject_allowlist.*' => ['string', 'max:80'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();
        $updated = $service->updateFlag($admin, $flag, $data['mode'], $data['rollout_percentage'], $data['audience'] ?? [], $data['expected_version'], $data['reason']);

        return ApiResponse::success($updated->only(['code', 'name', 'mode', 'rollout_percentage', 'audience', 'version', 'updated_at']));
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
        return ApiResponse::success(['items' => NotificationTemplate::query()->latest()->get()->map(fn (NotificationTemplate $template) => ['id' => $template->public_id, 'scene_code' => $template->scene_code, 'channel' => $template->channel, 'status' => $template->status, 'current_version_no' => $template->current_version_no])]);
    }

    public function saveTemplate(Request $request, NotificationService $service): JsonResponse
    {
        $data = $request->validate(['scene_code' => ['required', 'string', 'max:64'], 'channel' => ['required', 'in:in_app,wechat,sms'], 'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'allowed_variables' => ['required', 'array', 'max:20'], 'allowed_variables.*' => ['string', 'regex:/^[a-zA-Z0-9_]+$/'], 'provider_template_id' => ['nullable', 'string', 'max:160'], 'publish' => ['required', 'boolean']]); /** @var AdminUser $admin */
        $admin = $request->user();
        $template = $service->saveTemplate($admin, $data);

        return ApiResponse::success(['id' => $template->public_id, 'scene_code' => $template->scene_code, 'channel' => $template->channel, 'status' => $template->status, 'current_version_no' => $template->current_version_no], 201);
    }

    public function campaigns(): JsonResponse
    {
        return ApiResponse::success(['items' => NotificationCampaign::query()->latest()->get()->map(fn (NotificationCampaign $campaign) => ['id' => $campaign->public_id, 'name' => $campaign->name, 'title' => $campaign->title, 'channels' => $campaign->channels, 'status' => $campaign->status, 'recipient_count' => $campaign->recipient_count, 'sent_count' => $campaign->sent_count, 'scheduled_at' => $campaign->scheduled_at?->toIso8601String()])]);
    }

    public function createCampaign(Request $request, NotificationService $service): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:in_app,wechat,sms'], 'scheduled_at' => ['nullable', 'date']]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422); /** @var AdminUser $admin */
        $admin = $request->user();
        $campaign = $service->createCampaign($admin, $data, $key);

        return ApiResponse::success(['id' => $campaign->public_id, 'status' => $campaign->status, 'scheduled_at' => $campaign->scheduled_at?->toIso8601String()], 201);
    }

    public function deliveries(): JsonResponse
    {
        return ApiResponse::success(['items' => DB::table('notification_deliveries')->whereIn('status', ['retrying', 'failed'])->latest()->limit(100)->get()]);
    }
}
