<?php

namespace App\Modules\Support\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\WechatCustomerServiceMessageSender;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with('messages')->latest('updated_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        return ApiResponse::success(['items' => $query->limit(200)->get()->map(fn ($ticket) => $this->data($ticket))]);
    }

    public function show(SupportTicket $supportTicket): JsonResponse
    {
        return ApiResponse::success($this->data($supportTicket->load('messages')));
    }

    public function reply(
        Request $request,
        SupportTicket $supportTicket,
        WechatCustomerServiceMessageSender $wechat,
    ): JsonResponse {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:5000'],
            'status' => ['sometimes', 'in:processing,waiting_user,resolved'],
            'notify_wechat' => ['sometimes', 'boolean'],
        ]);
        $supportTicket->messages()->create(['sender_type' => 'admin', 'sender_id' => $request->user()->id, 'content' => $data['content'], 'attachment_file_ids' => []]);
        $status = $data['status'] ?? 'waiting_user';
        $supportTicket->update(['assigned_admin_id' => $request->user()->id, 'status' => $status, 'last_replied_at' => now(), 'closed_at' => $status === 'resolved' ? now() : null]);

        $wechatResult = null;
        if (($data['notify_wechat'] ?? false) === true) {
            $user = User::query()->find($supportTicket->user_id);
            $wechatResult = $user === null
                ? ['status' => 'skipped', 'message' => '工单用户不存在，未发送微信客服消息。']
                : $wechat->send($user, (string) $data['content']);
        }

        return ApiResponse::success([
            ...$this->data($supportTicket->fresh()->load('messages')),
            'wechat_notification' => $wechatResult,
        ]);
    }

    public function update(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,processing,waiting_user,waiting_service,resolved,closed'], 'priority' => ['sometimes', 'in:low,normal,high,urgent']]);
        $supportTicket->update([...$data, 'assigned_admin_id' => $request->user()->id, 'closed_at' => in_array($data['status'] ?? '', ['resolved', 'closed'], true) ? now() : null]);

        return ApiResponse::success($this->data($supportTicket->load('messages')));
    }

    private function data(SupportTicket $ticket): array
    {
        return ['id' => $ticket->public_id, 'user_id' => $ticket->user_id, 'category' => $ticket->category, 'subject' => $ticket->subject, 'status' => $ticket->status, 'priority' => $ticket->priority, 'related_type' => $ticket->related_type, 'related_public_id' => $ticket->related_public_id, 'assigned_admin_id' => $ticket->assigned_admin_id, 'created_at' => $ticket->created_at?->toIso8601String(), 'updated_at' => $ticket->updated_at?->toIso8601String(), 'messages' => $ticket->messages->map(fn ($message) => ['id' => $message->public_id, 'sender_type' => $message->sender_type, 'content' => $message->content, 'created_at' => $message->created_at?->toIso8601String()])];
    }
}
