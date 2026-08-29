<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCampusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Campus::query();
        $campusId = $request->attributes->get('campus_database_id');
        if ($request->attributes->get('campus_context_strict') === true && is_int($campusId)) {
            $query->whereKey($campusId);
        }

        $items = $query
            ->with(['agents.roles'])
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Campus $campus) => $this->present($campus));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateCampus($request);
        /** @var AdminUser $actor */
        $actor = $request->user();
        $campus = Campus::query()->create($data);
        $presented = $this->present($campus->load('agents')->loadCount('users'));
        $audit->record($actor, 'campus.create', 'campus', $campus->public_id, null, $presented);

        return ApiResponse::success($presented, 201, '学校已创建。');
    }

    public function update(Request $request, Campus $campus, AuditLogger $audit): JsonResponse
    {
        $before = $this->present($campus->load('agents')->loadCount('users'));
        $data = $this->validateCampus($request, $campus);
        /** @var AdminUser $actor */
        $actor = $request->user();
        $campus->update($data);
        $after = $this->present($campus->fresh()->load('agents.roles')->loadCount('users'));
        $audit->record($actor, 'campus.update', 'campus', $campus->public_id, $before, $after);

        return ApiResponse::success($after, 200, '学校资料已更新。');
    }

    public function agents(Request $request, Campus $campus, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'admin_user_ids' => ['present', 'array'],
            'admin_user_ids.*' => ['string', Rule::exists('admin_users', 'public_id')->where('status', 'active')],
            'primary_admin_user_id' => ['sometimes', 'nullable', 'string'],
        ]);
        $ids = AdminUser::query()->whereIn('public_id', $data['admin_user_ids'])->pluck('id', 'public_id');
        $primaryId = isset($data['primary_admin_user_id']) ? $ids->get($data['primary_admin_user_id']) : null;
        $sync = $ids->values()->mapWithKeys(fn (int $id) => [$id => ['is_primary' => $id === $primaryId]])->all();
        $before = $this->present($campus->load('agents.roles')->loadCount('users'));

        DB::transaction(fn () => $campus->agents()->sync($sync));
        $after = $this->present($campus->fresh()->load('agents.roles')->loadCount('users'));
        /** @var AdminUser $actor */
        $actor = $request->user();
        $audit->record($actor, 'campus.agents_update', 'campus', $campus->public_id, $before, $after);

        return ApiResponse::success($after, 200, '学校代理已更新。');
    }

    public function agentOptions(): JsonResponse
    {
        $items = AdminUser::query()->where('status', 'active')->with('roles')->orderBy('display_name')->get()
            ->map(fn (AdminUser $admin) => [
                'id' => $admin->public_id,
                'account' => $admin->account,
                'display_name' => $admin->display_name,
                'roles' => $admin->roles->pluck('code')->values(),
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    /** @return array<string, mixed> */
    private function validateCampus(Request $request, ?Campus $campus = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('campuses', 'code')->ignore($campus?->id)],
            'name' => ['required', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:60'],
            'district' => ['sometimes', 'nullable', 'string', 'max:60'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:active,disabled'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Campus $campus): array
    {
        return [
            'id' => $campus->public_id,
            'code' => $campus->code,
            'name' => $campus->name,
            'province' => $campus->province,
            'city' => $campus->city,
            'district' => $campus->district,
            'address' => $campus->address,
            'latitude' => $campus->latitude,
            'longitude' => $campus->longitude,
            'status' => $campus->status,
            'sort_order' => $campus->sort_order,
            'users_count' => $campus->users_count ?? 0,
            'agents' => $campus->agents->map(fn (AdminUser $admin) => [
                'id' => $admin->public_id,
                'account' => $admin->account,
                'display_name' => $admin->display_name,
                'is_primary' => (bool) $admin->pivot->is_primary,
                'roles' => $admin->roles->pluck('code')->values(),
            ])->values(),
        ];
    }
}
