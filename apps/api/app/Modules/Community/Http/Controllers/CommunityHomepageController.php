<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Community\Services\CommunityHomepageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityHomepageController extends Controller
{
    public function show(CommunityHomepageService $service): JsonResponse
    {
        return ApiResponse::success($service->read());
    }

    public function adminShow(CommunityHomepageService $service): JsonResponse
    {
        return ApiResponse::success($service->read());
    }

    public function update(Request $request, CommunityHomepageService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            // brand was added after the first community-decoration API shipped.
            // Accept older clients and inherit the currently published brand.
            'brand' => ['sometimes', 'array'],
            'brand.logo_url' => ['sometimes', 'nullable', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
            'brand.logo_text' => ['sometimes', 'string', 'max:8'],
            'brand.name' => ['sometimes', 'string', 'max:40'],
            'brand.caption' => ['sometimes', 'nullable', 'string', 'max:80'],
            'banner' => ['required', 'array'],
            'banner.title' => ['required', 'string', 'max:80'],
            'banner.subtitle' => ['required', 'string', 'max:160'],
            'banner.image_url' => ['nullable', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
            'banner.link_type' => ['required', 'in:posts,activities,tasks,none'],
            'banner.accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'quick_entries' => ['required', 'array', 'max:8'],
            'quick_entries.*.id' => ['required', 'string', 'max:32'],
            'quick_entries.*.label' => ['required', 'string', 'max:24'],
            'quick_entries.*.description' => ['nullable', 'string', 'max:60'],
            'quick_entries.*.icon_url' => ['nullable', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
            'quick_entries.*.target' => ['required', 'in:publish_post,activities,tasks,posts,notifications'],
            'quick_entries.*.enabled' => ['required', 'boolean'],
            'quick_entries.*.color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'quick_entries.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'hot_topics' => ['required', 'array', 'max:12'],
            'hot_topics.*.id' => ['required', 'string', 'max:32'],
            'hot_topics.*.label' => ['required', 'string', 'max:32'],
            'hot_topics.*.target' => ['required', 'string', 'max:32'],
            'hot_topics.*.enabled' => ['required', 'boolean'],
            'hot_topics.*.color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reason = $data['reason'];
        unset($data['reason']);
        $before = $service->read();
        $data['brand'] = [
            ...($before['brand'] ?? CommunityHomepageService::DEFAULTS['brand']),
            ...($data['brand'] ?? []),
        ];
        $updated = $service->update($request->user(), $data, $reason);
        $audit->record(
            $request->user(),
            'community.homepage.publish',
            'system_setting',
            CommunityHomepageService::SETTING_KEY,
            $before,
            $updated,
            $reason,
        );

        return ApiResponse::success($updated);
    }
}
