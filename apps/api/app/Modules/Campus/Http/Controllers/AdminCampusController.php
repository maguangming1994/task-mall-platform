<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusBrandingUrl;
use App\Modules\Campus\Services\CampusDirectorySyncService;
use App\Modules\Campus\Services\CampusReferenceLocations;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCampusController extends Controller
{
    public function __construct(private readonly CampusBrandingUrl $branding, private readonly CampusReferenceLocations $references) {}

    public function index(Request $request, CampusDirectorySyncService $directory): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'province' => ['sometimes', 'string', 'max:60'],
            'catalog_type' => ['sometimes', 'in:ordinary,adult,manual'],
            'education_level' => ['sometimes', 'string', 'max:40'],
            'catalog_status' => ['sometimes', 'in:listed,unlisted,manual'],
            'status' => ['sometimes', 'in:active,disabled'],
            'agent_coverage' => ['sometimes', 'in:assigned,unassigned,primary_missing'],
            'branding_status' => ['sometimes', 'in:configured,missing'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $scope = $this->scopedCampuses($request);
        $query = clone $scope;
        $query
            ->when(isset($filters['keyword']), function (Builder $query) use ($filters): void {
                $keyword = '%'.trim($filters['keyword']).'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('name', 'like', $keyword)
                    ->orWhere('code', 'like', $keyword)
                    ->orWhere('official_school_code', 'like', $keyword)
                    ->orWhere('city', 'like', $keyword)
                    ->orWhere('supervising_authority', 'like', $keyword));
            })
            ->when(isset($filters['province']), fn (Builder $query) => $query->where('province', $filters['province']))
            ->when(isset($filters['catalog_type']), fn (Builder $query) => $query->where('catalog_type', $filters['catalog_type']))
            ->when(isset($filters['education_level']), fn (Builder $query) => $query->where('education_level', $filters['education_level']))
            ->when(isset($filters['catalog_status']), fn (Builder $query) => $query->where('catalog_status', $filters['catalog_status']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['agent_coverage'] ?? null) === 'assigned', fn (Builder $query) => $query->has('agents'))
            ->when(($filters['agent_coverage'] ?? null) === 'unassigned', fn (Builder $query) => $query->doesntHave('agents'))
            ->when(($filters['agent_coverage'] ?? null) === 'primary_missing', fn (Builder $query) => $query->whereDoesntHave(
                'agents',
                fn (Builder $agents) => $agents->where('admin_user_campus.is_primary', true),
            ))
            ->when(isset($filters['branding_status']), fn (Builder $query) => $this->applyBrandingStatus($query, $filters['branding_status']));

        $paginator = $query
            ->with(['agents.roles', 'emblemAsset', 'logoAsset'])
            ->withCount('users')
            ->orderBy('status')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate((int) ($filters['page_size'] ?? 20));

        $campusIdQuery = (clone $scope)->select('campuses.id');
        $summary = [
            'total' => (clone $scope)->count(),
            'active' => (clone $scope)->where('status', 'active')->count(),
            'disabled' => (clone $scope)->where('status', 'disabled')->count(),
            'official_listed' => (clone $scope)->where('catalog_status', 'listed')->count(),
            'branding_configured' => $this->applyBrandingStatus(clone $scope, 'configured')->count(),
            'branding_missing' => $this->applyBrandingStatus(clone $scope, 'missing')->count(),
            'without_primary_agent' => (clone $scope)
                ->where('status', 'active')
                ->whereDoesntHave(
                    'agents',
                    fn (Builder $agents) => $agents->where('admin_user_campus.is_primary', true),
                )->count(),
            'agent_total' => DB::table('admin_user_campus')->whereIn('campus_id', clone $campusIdQuery)->distinct()->count('admin_user_id'),
            'user_total' => DB::table('users')->whereIn('campus_id', clone $campusIdQuery)->count(),
        ];
        $provinces = (clone $scope)->whereNotNull('province')->distinct()->orderBy('province')->pluck('province')->values();
        $catalog = $directory->metadata();
        $latestSync = (clone $scope)->whereNotNull('catalog_synced_at')->max('catalog_synced_at');
        $catalog['database'] = [
            'listed' => (clone $scope)->where('catalog_status', 'listed')->count(),
            'unlisted' => (clone $scope)->where('catalog_status', 'unlisted')->count(),
            'last_synced_at' => $latestSync,
        ];

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (Campus $campus) => $this->present($campus))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $summary,
            'filters' => ['provinces' => $provinces],
            'catalog' => $catalog,
        ]);
    }

    public function show(Campus $campus): JsonResponse
    {
        return ApiResponse::success($this->present(
            $campus->load(['agents.roles', 'emblemAsset', 'logoAsset'])->loadCount('users'),
        ));
    }

    public function agentIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,disabled'],
            'assignment' => ['sometimes', 'in:assigned,unassigned'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);
        $base = AdminUser::query()->whereHas('roles', fn (Builder $query) => $query->where('code', 'campus_agent'));
        $query = clone $base;
        $query
            ->when(isset($filters['keyword']), function (Builder $query) use ($filters): void {
                $keyword = '%'.trim($filters['keyword']).'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('account', 'like', $keyword)
                    ->orWhere('display_name', 'like', $keyword)
                    ->orWhere('email', 'like', $keyword));
            })
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['assignment'] ?? null) === 'assigned', fn (Builder $query) => $query->has('campuses'))
            ->when(($filters['assignment'] ?? null) === 'unassigned', fn (Builder $query) => $query->doesntHave('campuses'));

        $paginator = $query
            ->with(['roles', 'campuses' => fn ($campuses) => $campuses->orderByDesc('admin_user_campus.is_primary')->orderBy('campuses.name')])
            ->withCount('campuses')
            ->orderBy('display_name')
            ->paginate((int) ($filters['page_size'] ?? 20));
        $pageAdminIds = $paginator->getCollection()->pluck('id');
        $coveredUsers = $pageAdminIds->isEmpty()
            ? collect()
            : DB::table('admin_user_campus')
                ->leftJoin('users', 'users.campus_id', '=', 'admin_user_campus.campus_id')
                ->whereIn('admin_user_campus.admin_user_id', $pageAdminIds)
                ->groupBy('admin_user_campus.admin_user_id')
                ->selectRaw('admin_user_campus.admin_user_id, count(distinct users.id) as aggregate')
                ->pluck('aggregate', 'admin_user_campus.admin_user_id');

        $allAgentIds = (clone $base)->select('admin_users.id');
        $coveredCampusIds = DB::table('admin_user_campus')->whereIn('admin_user_id', clone $allAgentIds)->select('campus_id');
        $summary = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'unassigned' => (clone $base)->doesntHave('campuses')->count(),
            'campuses_covered' => DB::table('admin_user_campus')->whereIn('admin_user_id', clone $allAgentIds)->distinct()->count('campus_id'),
            'users_covered' => DB::table('users')->whereIn('campus_id', $coveredCampusIds)->count(),
        ];

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (AdminUser $admin) => [
                'id' => $admin->public_id,
                'account' => $admin->account,
                'display_name' => $admin->display_name,
                'email' => $admin->email,
                'status' => $admin->status,
                'requires_mfa' => $admin->requires_mfa,
                'last_login_at' => $admin->last_login_at?->toIso8601String(),
                'campuses_count' => (int) $admin->campuses_count,
                'covered_users_count' => (int) ($coveredUsers[$admin->id] ?? 0),
                'campuses' => $admin->campuses->map(fn (Campus $campus) => [
                    'id' => $campus->public_id,
                    'code' => $campus->code,
                    'name' => $campus->name,
                    'status' => $campus->status,
                    'is_primary' => (bool) $campus->pivot->is_primary,
                ])->values(),
            ])->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = [
            ...$this->validateCampus($request),
            'catalog_type' => 'manual',
            'catalog_status' => 'manual',
        ];
        /** @var AdminUser $actor */
        $actor = $request->user();
        $campus = Campus::query()->create($data);
        $presented = $this->present($campus->load(['agents.roles', 'emblemAsset', 'logoAsset'])->loadCount('users'));
        $audit->record($actor, 'campus.create', 'campus', $campus->public_id, null, $presented);

        return ApiResponse::success($presented, 201, '学校已创建。');
    }

    public function update(Request $request, Campus $campus, AuditLogger $audit): JsonResponse
    {
        $before = $this->present($campus->load(['agents.roles', 'emblemAsset', 'logoAsset'])->loadCount('users'));
        $data = $this->validateCampus($request, $campus);
        if ($campus->official_school_code !== null) {
            unset($data['code'], $data['name'], $data['province'], $data['city']);
        }
        /** @var AdminUser $actor */
        $actor = $request->user();
        $campus->update($data);
        $after = $this->present($campus->fresh()->load(['agents.roles', 'emblemAsset', 'logoAsset'])->loadCount('users'));
        $audit->record($actor, 'campus.update', 'campus', $campus->public_id, $before, $after);

        return ApiResponse::success($after, 200, '学校资料已更新。');
    }

    public function agents(Request $request, Campus $campus, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'admin_user_ids' => ['present', 'array'],
            'admin_user_ids.*' => ['string', 'distinct'],
            'primary_admin_user_id' => ['sometimes', 'nullable', 'string'],
        ]);
        $adminPublicIds = array_values(array_unique($data['admin_user_ids']));
        if (($data['primary_admin_user_id'] ?? null) !== null && ! in_array($data['primary_admin_user_id'], $adminPublicIds, true)) {
            throw ValidationException::withMessages(['primary_admin_user_id' => ['主代理必须包含在已选代理账号中。']]);
        }

        $admins = AdminUser::query()
            ->whereIn('public_id', $adminPublicIds)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query) => $query->where('code', 'campus_agent'))
            ->get();
        if ($admins->count() !== count($adminPublicIds)) {
            throw ValidationException::withMessages(['admin_user_ids' => ['只能分配状态正常且具有“学校代理”角色的管理员账号。']]);
        }

        $ids = $admins->pluck('id', 'public_id');
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
        $items = AdminUser::query()
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query) => $query->where('code', 'campus_agent'))
            ->with('roles')
            ->orderBy('display_name')
            ->get()
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
        $data = $request->validate([
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
            'emblem_asset_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('media_assets', 'public_id')->where('status', 'active')],
            'emblem_url' => ['sometimes', 'nullable', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
            'logo_asset_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('media_assets', 'public_id')->where('status', 'active')],
            'logo_url' => ['sometimes', 'nullable', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
        ]);

        foreach (['emblem', 'logo'] as $image) {
            $assetKey = $image.'_asset_id';
            $urlKey = $image.'_url';
            if (filled($data[$assetKey] ?? null)) {
                $assetUrl = MediaAsset::query()
                    ->where('public_id', $data[$assetKey])
                    ->where('status', 'active')
                    ->value('public_url');
                if (! is_string($assetUrl) || $assetUrl === '') {
                    throw ValidationException::withMessages([$assetKey => ['The selected media asset is unavailable.']]);
                }
                $data[$urlKey] = $assetUrl;
            } elseif (array_key_exists($urlKey, $data) && ! array_key_exists($assetKey, $data)) {
                $data[$assetKey] = null;
            }
        }

        return $data;
    }

    private function applyBrandingStatus(Builder $query, string $status): Builder
    {
        if ($status === 'configured') {
            return $query->where(fn (Builder $branding) => $branding
                ->where(fn (Builder $managed) => $managed
                    ->whereNotNull('emblem_asset_id')
                    ->where('emblem_asset_id', '<>', '')
                    ->whereHas('emblemAsset', fn (Builder $asset) => $asset
                        ->where('status', 'active')
                        ->whereNotNull('public_url')
                        ->where('public_url', '<>', '')))
                ->orWhere(fn (Builder $remote) => $remote
                    ->where(fn (Builder $asset) => $asset
                        ->whereNull('emblem_asset_id')
                        ->orWhere('emblem_asset_id', ''))
                    ->whereNotNull('emblem_url')
                    ->where('emblem_url', '<>', '')));
        }

        return $query->where(fn (Builder $branding) => $branding
            ->where(fn (Builder $managed) => $managed
                ->whereNotNull('emblem_asset_id')
                ->where('emblem_asset_id', '<>', '')
                ->whereDoesntHave('emblemAsset', fn (Builder $asset) => $asset
                    ->where('status', 'active')
                    ->whereNotNull('public_url')
                    ->where('public_url', '<>', '')))
            ->orWhere(fn (Builder $remote) => $remote
                ->where(fn (Builder $asset) => $asset
                    ->whereNull('emblem_asset_id')
                    ->orWhere('emblem_asset_id', ''))
                ->where(fn (Builder $url) => $url
                    ->whereNull('emblem_url')
                    ->orWhere('emblem_url', ''))));
    }

    private function scopedCampuses(Request $request): Builder
    {
        $query = Campus::query();
        $campusId = $request->attributes->get('campus_database_id');
        if ($request->attributes->get('campus_context_strict') === true && is_int($campusId)) {
            $query->whereKey($campusId);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(Campus $campus): array
    {
        return [
            'id' => $campus->public_id,
            'code' => $campus->code,
            'official_school_code' => $campus->official_school_code,
            'name' => $campus->name,
            'catalog_type' => $campus->catalog_type,
            'education_level' => $campus->education_level,
            'supervising_authority' => $campus->supervising_authority,
            'official_note' => $campus->official_note,
            'catalog_status' => $campus->catalog_status,
            'catalog_version' => $campus->catalog_version,
            'province' => $campus->province,
            'city' => $campus->city,
            'district' => $campus->district,
            'address' => $campus->address,
            'latitude' => $campus->latitude,
            'longitude' => $campus->longitude,
            'reference_locations' => $this->references->forCampus($campus),
            'coordinate_system' => 'WGS84',
            'emblem_asset_id' => $campus->emblem_asset_id,
            'emblem_url' => $this->brandUrl($campus->emblem_asset_id, $campus->emblemAsset, $campus->emblem_url),
            'logo_asset_id' => $campus->logo_asset_id,
            'logo_url' => $this->brandUrl($campus->logo_asset_id, $campus->logoAsset, $campus->logo_url),
            'status' => $campus->status,
            'sort_order' => $campus->sort_order,
            'users_count' => $campus->users_count ?? 0,
            'updated_at' => $campus->updated_at?->toIso8601String(),
            'agents' => $campus->agents->map(fn (AdminUser $admin) => [
                'id' => $admin->public_id,
                'account' => $admin->account,
                'display_name' => $admin->display_name,
                'is_primary' => (bool) $admin->pivot->is_primary,
                'roles' => $admin->roles->pluck('code')->values(),
            ])->values(),
        ];
    }

    private function brandUrl(?string $assetId, ?MediaAsset $asset, ?string $fallback): ?string
    {
        return $this->branding->resolve($assetId, $asset, $fallback);
    }
}
