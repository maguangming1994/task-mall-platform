<?php

namespace App\Modules\Support\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = SupportTicket::query()->where('user_id', $request->user()->id)->with('messages')->latest('updated_at')->get();

        return ApiResponse::success(['items' => $items->map(fn (SupportTicket $ticket): array => $this->data($ticket))]);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $this->own($request, $supportTicket);

        return ApiResponse::success($this->data($supportTicket->load('messages')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:task,order,after_sale,assets,account,community,other'],
            'subject' => ['required', 'string', 'max:160'],
            'content' => ['required', 'string', 'min:5', 'max:5000'],
            'related_type' => ['nullable', 'in:task,order,after_sale,asset,community'],
            'related_public_id' => ['nullable', 'string', 'size:26'],
            'attachment_file_ids' => ['nullable', 'array', 'max:6'],
            'attachment_file_ids.*' => ['string', 'size:26'],
        ]);
        $ticket = DB::transaction(function () use ($request, $data): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'user_id' => $request->user()->id, 'category' => $data['category'], 'subject' => $data['subject'],
                'status' => 'open', 'priority' => 'normal', 'related_type' => $data['related_type'] ?? null,
                'related_public_id' => $data['related_public_id'] ?? null, 'last_replied_at' => now(),
            ]);
            $ticket->messages()->create([
                'sender_type' => 'user', 'sender_id' => $request->user()->id, 'content' => $data['content'],
                'attachment_file_ids' => $data['attachment_file_ids'] ?? [],
            ]);

            return $ticket;
        });

        return ApiResponse::success($this->data($ticket->load('messages')), 201);
    }

    public function reply(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $this->own($request, $supportTicket);
        abort_if(in_array($supportTicket->status, ['resolved', 'closed'], true), 422, '工单已结束，如有新问题请重新提交。');
        $data = $request->validate(['content' => ['required', 'string', 'min:1', 'max:5000'], 'attachment_file_ids' => ['nullable', 'array', 'max:6'], 'attachment_file_ids.*' => ['string', 'size:26']]);
        $supportTicket->messages()->create(['sender_type' => 'user', 'sender_id' => $request->user()->id, 'content' => $data['content'], 'attachment_file_ids' => $data['attachment_file_ids'] ?? []]);
        $supportTicket->update(['status' => 'waiting_service', 'last_replied_at' => now()]);

        return ApiResponse::success($this->data($supportTicket->fresh()->load('messages')));
    }

    public function close(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $this->own($request, $supportTicket);
        $supportTicket->update(['status' => 'closed', 'closed_at' => now()]);

        return ApiResponse::success($this->data($supportTicket->load('messages')));
    }

    private function own(Request $request, SupportTicket $ticket): void
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
    }

    /** @return array<string,mixed> */
    private function data(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->public_id, 'category' => $ticket->category, 'subject' => $ticket->subject,
            'status' => $ticket->status, 'priority' => $ticket->priority, 'workflow' => $this->workflow($ticket->status),
            'related_type' => $ticket->related_type, 'related_public_id' => $ticket->related_public_id,
            'created_at' => $ticket->created_at?->toIso8601String(), 'updated_at' => $ticket->updated_at?->toIso8601String(),
            'messages' => $ticket->relationLoaded('messages') ? $ticket->messages->map(fn ($message): array => [
                'id' => $message->public_id, 'sender_type' => $message->sender_type, 'content' => $message->content,
                'attachment_file_ids' => $message->attachment_file_ids ?? [], 'created_at' => $message->created_at?->toIso8601String(),
            ]) : [],
        ];
    }

    /** @return array{status_label:string,next_action:string,can_reply:bool,can_close:bool} */
    private function workflow(string $status): array
    {
        return match ($status) {
            'open' => ['status_label' => '待客服受理', 'next_action' => '客服受理后会在此回复，你也可以继续补充信息。', 'can_reply' => true, 'can_close' => true],
            'processing' => ['status_label' => '客服处理中', 'next_action' => '客服正在核查相关记录，请留意后续回复。', 'can_reply' => true, 'can_close' => true],
            'waiting_user' => ['status_label' => '等待你回复', 'next_action' => '请按客服提示补充信息，回复后将继续处理。', 'can_reply' => true, 'can_close' => true],
            'waiting_service' => ['status_label' => '等待客服回复', 'next_action' => '你的补充已提交，请勿重复创建相同问题。', 'can_reply' => true, 'can_close' => true],
            'resolved' => ['status_label' => '客服已解决', 'next_action' => '请确认处理结果；仍有问题可重新提交工单。', 'can_reply' => false, 'can_close' => true],
            'closed' => ['status_label' => '工单已关闭', 'next_action' => '本次沟通已结束，如有新问题请创建新工单。', 'can_reply' => false, 'can_close' => false],
            default => ['status_label' => '状态同步中', 'next_action' => '请稍后刷新工单状态。', 'can_reply' => false, 'can_close' => false],
        };
    }
}
