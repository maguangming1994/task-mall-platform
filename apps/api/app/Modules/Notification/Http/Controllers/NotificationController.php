<?php

namespace App\Modules\Notification\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:100'], 'category' => ['nullable', 'in:task,asset,order,after_sale,community,system'], 'unread_only' => ['nullable', 'boolean']]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = Notification::query()->where('user_id', $request->user()->id)->latest();
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['unread_only'])) {
            $query->whereNull('read_at');
        }
        $total = $query->count();

        return ApiResponse::success(['items' => $query->forPage($page, $size)->get()->map(fn (Notification $notification) => $this->data($notification)), 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success($this->data($notification->fresh()));
    }

    public function preference(Request $request, NotificationService $service): JsonResponse
    {
        return ApiResponse::success($service->preference($request->user())->only(['in_app_enabled', 'wechat_enabled', 'sms_enabled', 'marketing_subscribed', 'quiet_hours_start', 'quiet_hours_end']));
    }

    public function updatePreference(Request $request, NotificationService $service): JsonResponse
    {
        $data = $request->validate(['in_app_enabled' => ['required', 'boolean'], 'wechat_enabled' => ['required', 'boolean'], 'sms_enabled' => ['required', 'boolean'], 'marketing_subscribed' => ['required', 'boolean'], 'quiet_hours_start' => ['required', 'date_format:H:i'], 'quiet_hours_end' => ['required', 'date_format:H:i']]);
        $preference = $service->preference($request->user());
        $preference->update($data);

        return ApiResponse::success($preference->fresh()->only(array_keys($data)));
    }

    private function data(Notification $notification): array
    {
        return ['id' => $notification->public_id, 'category' => $notification->category, 'scene_code' => $notification->scene_code, 'title' => $notification->title, 'body' => $notification->body, 'data' => $notification->data, 'essential' => $notification->essential, 'read_at' => $notification->read_at?->toIso8601String(), 'created_at' => $notification->created_at->toIso8601String()];
    }
}
