<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Models\IdentityConflict;
use App\Modules\Auth\Services\IdentityConflictService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityConflictController extends Controller
{
    public function index(Request $request, IdentityConflictService $conflicts): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,resolved,rejected'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $items = IdentityConflict::query()
            ->where('status', $data['status'] ?? 'pending')
            ->latest('id')
            ->limit((int) ($data['page_size'] ?? 50))
            ->get()
            ->map(fn (IdentityConflict $conflict) => $conflicts->present($conflict));

        return ApiResponse::success(['items' => $items]);
    }

    public function resolve(
        Request $request,
        IdentityConflict $identityConflict,
        IdentityConflictService $conflicts,
    ): JsonResponse {
        $data = $request->validate([
            'resolution' => ['required', 'in:attach_to_target,reject'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'target_user_id' => ['nullable', 'string', 'size:26'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($conflicts->resolve(
            $identityConflict,
            $admin,
            $data['resolution'],
            $data['reason'],
            $data['target_user_id'] ?? null,
        ));
    }
}
