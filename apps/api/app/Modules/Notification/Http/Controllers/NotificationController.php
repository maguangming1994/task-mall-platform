<?php

namespace App\Modules\Notification\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Services\NotificationSceneConfigService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Notification\Services\WechatSubscriptionGrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    private const CATEGORIES = ['task', 'asset', 'order', 'after_sale', 'community', 'system', 'second_hand', 'material'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:100'], 'category' => ['nullable', 'in:task,asset,order,after_sale,community,system,second_hand,material'], 'unread_only' => ['nullable', 'boolean']]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);
        $userId = $request->user()->id;
        $query = Notification::query()->where('user_id', $userId)->latest('created_at')->latest('id');
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['unread_only'])) {
            $query->whereNull('read_at');
        }
        $total = $query->count();
        $unreadQuery = Notification::query()->where('user_id', $userId)->whereNull('read_at');
        $unreadTotal = (clone $unreadQuery)->count();
        $categoryUnreadCounts = array_fill_keys(self::CATEGORIES, 0);
        foreach ((clone $unreadQuery)->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category') as $category => $count) {
            if (array_key_exists((string) $category, $categoryUnreadCounts)) {
                $categoryUnreadCounts[(string) $category] = (int) $count;
            }
        }

        return ApiResponse::success([
            'items' => $query->forPage($page, $size)->get()->map(fn (Notification $notification) => $this->data($notification)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total, 'has_more' => $page * $size < $total],
            'unread_total' => $unreadTotal,
            'category_unread_counts' => $categoryUnreadCounts,
        ]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success($this->data($notification->fresh()));
    }

    public function readAll(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', 'in:task,asset,order,after_sale,community,system,second_hand,material']]);
        $query = Notification::query()->where('user_id', $request->user()->id)->whereNull('read_at');
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        $updated = $query->update(['read_at' => now()]);
        $unreadTotal = Notification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return ApiResponse::success(['updated_count' => $updated, 'unread_total' => $unreadTotal]);
    }

    public function preference(Request $request, NotificationService $service): JsonResponse
    {
        return ApiResponse::success($this->preferenceData($service->preference($request->user())));
    }

    public function updatePreference(Request $request, NotificationService $service): JsonResponse
    {
        $data = $request->validate(['in_app_enabled' => ['required', 'boolean'], 'wechat_enabled' => ['required', 'boolean'], 'sms_enabled' => ['required', 'boolean'], 'marketing_subscribed' => ['required', 'boolean'], 'quiet_hours_start' => ['required', 'date_format:H:i'], 'quiet_hours_end' => ['required', 'date_format:H:i']]);
        $preference = $service->preference($request->user());
        $preference->update($data);

        return ApiResponse::success($this->preferenceData($preference->fresh()));
    }

    public function wechatSubscriptionOptions(Request $request, WechatSubscriptionGrantService $service): JsonResponse
    {
        return ApiResponse::success($service->options($this->wechatIdentity($request)));
    }

    public function recordWechatSubscriptions(Request $request, WechatSubscriptionGrantService $service): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'results' => ['required', 'array', 'min:1', 'max:3'],
            'results.*.template_id' => ['required', 'string', 'max:160', 'distinct:strict'],
            'results.*.status' => ['required', 'in:accept,acceptWithAudio,reject,ban,filter'],
        ]);

        return ApiResponse::success($service->record($this->wechatIdentity($request), $data['results'], $data['request_id']));
    }

    public function wechatOfficialAccountTemplates(NotificationSceneConfigService $configs): JsonResponse
    {
        $templates = $configs->wechatOfficialAccountTemplates();

        return ApiResponse::success(['configured' => $templates !== [], 'templates' => $templates]);
    }

    private function wechatIdentity(Request $request): UserIdentity
    {
        $session = $request->attributes->get('auth_session');
        $identity = $session instanceof UserSession && $session->user_identity_id !== null
            ? UserIdentity::query()->find($session->user_identity_id)
            : null;
        if ($identity === null || $identity->user_id !== $request->user()->id || $identity->provider !== 'wechat_mini') {
            throw ValidationException::withMessages(['subscription' => '请在微信小程序中重新登录后再授权订阅消息。']);
        }

        return $identity;
    }

    private function data(Notification $notification): array
    {
        return ['id' => $notification->public_id, 'category' => $notification->category, 'scene_code' => $notification->scene_code, 'title' => $notification->title, 'body' => $notification->body, 'data' => $notification->data, 'essential' => $notification->essential, 'read_at' => $notification->read_at?->toIso8601String(), 'created_at' => $notification->created_at->toIso8601String()];
    }

    private function preferenceData(NotificationPreference $preference): array
    {
        return [
            'in_app_enabled' => $preference->in_app_enabled,
            'wechat_enabled' => $preference->wechat_enabled,
            'sms_enabled' => $preference->sms_enabled,
            'marketing_subscribed' => $preference->marketing_subscribed,
            'quiet_hours_start' => substr((string) $preference->quiet_hours_start, 0, 5),
            'quiet_hours_end' => substr((string) $preference->quiet_hours_end, 0, 5),
        ];
    }
}
