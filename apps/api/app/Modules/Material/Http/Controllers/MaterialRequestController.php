<?php

namespace App\Modules\Material\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Material\Models\MaterialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:60'],
            'requirement' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $user = $request->user();
        $record = MaterialRequest::query()->create([
            'user_id' => $user->id,
            'campus_id' => $user->campus_id,
            'title' => $data['title'],
            'requirement' => $data['requirement'],
            'status' => 'pending',
        ]);

        return ApiResponse::success([
            'request' => $this->present($record),
            'message' => '需求已提交，仅你与管理员可见，不消耗资料获取次数。',
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = MaterialRequest::query()->where('user_id', $request->user()->id)->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (MaterialRequest $r) => $this->present($r)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MaterialRequest $request): array
    {
        return [
            'id' => $request->public_id,
            'title' => $request->title,
            'requirement' => $request->requirement,
            'status' => $request->status,
            'admin_note' => $request->admin_note,
            'created_at' => $request->created_at?->toIso8601String(),
            'handled_at' => $request->handled_at?->toIso8601String(),
        ];
    }
}
