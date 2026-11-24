<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Community\Models\CommunityContentSecurityLog;
use App\Modules\Community\Services\AliyunContentSecurityClient;
use App\Modules\Community\Services\CommunityContentSecuritySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminCommunityContentSecurityController extends Controller
{
    public function show(CommunityContentSecuritySettingsService $settings): JsonResponse
    {
        return ApiResponse::success($settings->snapshot());
    }

    public function update(Request $request, CommunityContentSecuritySettingsService $settings, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', 'in:wechat,aliyun'],
            'access_key_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'access_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'clear_credentials' => ['sometimes', 'boolean'],
            'region' => ['required', 'string', 'max:64'],
            'endpoint' => ['required', 'string', 'max:255'],
            'service' => ['required', 'string', 'max:128'],
        ]);
        if (($data['clear_credentials'] ?? false) && (filled($data['access_key_id'] ?? null) || filled($data['access_key_secret'] ?? null))) {
            throw ValidationException::withMessages(['clear_credentials' => '不能同时填写新凭证和勾选清除已保存凭证。']);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $after = $settings->update($data);
        $audit->record($admin, 'community.content_security.update', 'community_content_security', 'default', $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, '内容安全配置已保存。');
    }

    public function test(
        Request $request,
        CommunityContentSecuritySettingsService $settings,
        WechatAccessTokenService $tokens,
        AliyunContentSecurityClient $aliyun,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $configuration = $settings->configuration();

        try {
            if ($configuration['provider'] === 'aliyun') {
                if (! $configuration['configured']) {
                    throw ValidationException::withMessages(['aliyun' => '请先保存完整的阿里云 AccessKey。']);
                }
                $result = $aliyun->scan($configuration, '校园学习经验分享。', 'settings-test-'.Str::ulid());
                $settings->recordTest('success', '阿里云 TextModerationPlus 调用成功，判定：'.$result['verdict'].'。');
                $status = 'success';
            } else {
                $tokens->token('mini');
                $settings->recordTest('credential_only', '微信小程序 AccessToken 获取成功；仅完成凭证校验，未执行真实用户文本检测。');
                $status = 'credential_only';
            }
        } catch (Throwable $exception) {
            $message = $exception instanceof ValidationException
                ? (string) collect($exception->errors())->flatten()->first()
                : ($configuration['provider'] === 'aliyun' ? '阿里云内容安全测试失败，请检查凭证、地域、端点和服务码。' : '微信凭证校验失败，请检查小程序配置。');
            $settings->recordTest('failed', $message);
            $after = $settings->snapshot();
            $audit->record($admin, 'community.content_security.test_failed', 'community_content_security', 'default', null, $after, $data['reason']);

            return ApiResponse::error('CONTENT_SECURITY_TEST_FAILED', $message, 422);
        }

        $after = $settings->snapshot();
        $audit->record($admin, 'community.content_security.test', 'community_content_security', 'default', null, $after, $data['reason']);

        return ApiResponse::success($after, 200, $status === 'credential_only' ? '微信凭证校验完成。' : '阿里云内容安全测试完成。');
    }

    public function records(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'content_type' => ['nullable', 'in:post,comment'],
            'provider' => ['nullable', 'in:wechat,aliyun'],
            'verdict' => ['nullable', 'in:pass,review,block,skipped'],
            'risk_level' => ['nullable', 'in:none,low,medium,high'],
            'keyword' => ['nullable', 'string', 'max:120'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);
        $base = CommunityContentSecurityLog::query();
        $query = clone $base;
        foreach (['content_type', 'provider', 'verdict', 'risk_level'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['keyword'])) {
            $keyword = '%'.$data['keyword'].'%';
            $query->where(function ($query) use ($keyword): void {
                $query->where('public_id', 'like', $keyword)
                    ->orWhere('content_public_id', 'like', $keyword)
                    ->orWhere('subject_snapshot', 'like', $keyword)
                    ->orWhere('content_snapshot', 'like', $keyword);
            });
        }
        $total = $query->count();
        $records = $query->latest('id')->forPage($page, $size)->get();
        $authors = User::query()->whereIn('id', $records->pluck('user_id')->unique())->get(['id', 'public_id', 'nickname'])->keyBy('id');
        $counts = (clone $base)->selectRaw('verdict, count(*) as aggregate')->groupBy('verdict')->pluck('aggregate', 'verdict');
        $stats = ['total' => (clone $base)->count()];
        foreach (['pass', 'review', 'block', 'skipped'] as $verdict) {
            $stats[$verdict] = (int) ($counts[$verdict] ?? 0);
        }

        return ApiResponse::success([
            'items' => $records->map(function (CommunityContentSecurityLog $record) use ($authors): array {
                $author = $authors->get($record->user_id);

                return [
                    'id' => $record->public_id,
                    'content_type' => $record->content_type,
                    'content_id' => $record->content_public_id,
                    'content_version_no' => $record->content_version_no,
                    'content_field' => $record->content_field,
                    'subject_snapshot' => $record->subject_snapshot,
                    'content_snapshot' => $record->content_snapshot,
                    'author' => ['id' => $author?->public_id ?? '', 'nickname' => $author?->nickname ?: '社区用户'],
                    'provider' => $record->provider,
                    'verdict' => $record->verdict,
                    'risk_level' => $record->risk_level,
                    'risk_labels' => $record->risk_labels ?? [],
                    'skip_reason' => $record->skip_reason,
                    'request_id' => $record->request_id,
                    'response_time_ms' => $record->response_time_ms,
                    'created_at' => $record->created_at->toIso8601String(),
                ];
            })->values(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))],
            'stats' => $stats,
        ]);
    }
}
