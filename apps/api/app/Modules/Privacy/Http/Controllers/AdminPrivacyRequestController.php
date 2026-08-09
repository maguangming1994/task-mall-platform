<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Services\PrivacyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPrivacyRequestController extends Controller
{
    public function index(Request $request, PrivacyRequestService $privacy): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:submitted,verifying,processing,completed,partially_completed,rejected'],
            'request_type' => ['sometimes', 'in:access,copy,correction,deletion,withdraw_consent'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $items = PrivacyRequest::query()
            ->with(['resultFile', 'user:id,public_id,nickname,status'])
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['request_type']), fn ($query) => $query->where('request_type', $data['request_type']))
            ->latest('id')
            ->paginate($data['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => $items->getCollection()->map(fn (PrivacyRequest $item) => [
                ...$privacy->present($item),
                'user' => [
                    'id' => $item->user->public_id,
                    'nickname' => $item->user->nickname,
                    'status' => $item->user->status,
                ],
            ])->values(),
            'pagination' => [
                'page' => $items->currentPage(),
                'page_size' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function resolve(
        Request $request,
        PrivacyRequest $privacyRequest,
        PrivacyRequestService $privacy,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:completed,partially_completed,rejected'],
            'resolution_note' => ['required', 'string', 'min:4', 'max:1000'],
            'confirm_identity_verified' => ['required', 'accepted'],
        ]);
        if (! in_array($privacyRequest->status, ['submitted', 'verifying', 'processing'], true)) {
            return ApiResponse::error('PRIVACY_REQUEST_STATE_INVALID', '该隐私请求已经完成处理。', 409);
        }
        if ($data['decision'] === 'completed' && ($privacyRequest->blockers ?? []) !== []) {
            return ApiResponse::error('PRIVACY_REQUEST_BLOCKED', '存在法定保留或业务阻断，不能标记为全部完成。', 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $privacy->present($privacyRequest);
        $privacyRequest->update([
            'status' => $data['decision'],
            'resolution_note' => $data['resolution_note'],
            'resolved_by_admin_id' => $admin->id,
            'completed_at' => now(),
        ]);
        $after = $privacy->present($privacyRequest->fresh());
        $audit->record(
            $admin,
            'privacy_request.resolve',
            'privacy_request',
            $privacyRequest->public_id,
            $before,
            $after,
            $data['resolution_note'],
        );

        return ApiResponse::success($after);
    }
}
