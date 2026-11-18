<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Audit\Services\AuditPayloadRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request, AuditPayloadRedactor $redactor): JsonResponse
    {
        /** @var mixed $actor */
        $actor = $request->user();
        if (! $actor instanceof AdminUser) {
            return ApiResponse::error('ADMIN_AUTH_REQUIRED', '请先登录管理后台。', 401);
        }

        $canViewAll = $actor->hasPermission('audit.view');
        $canViewOwn = $actor->hasPermission('audit.view_own');
        if (! $canViewAll && ! $canViewOwn) {
            return ApiResponse::error('ADMIN_PERMISSION_DENIED', '当前账号无权查看审计日志。', 403);
        }

        $filters = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor' => ['sometimes', 'nullable', 'string', 'max:100'],
            'request_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'ended_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_at'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = OperationLog::query()
            ->with('adminUser:id,public_id,account,display_name');

        if (! $canViewAll) {
            $query->where('admin_user_id', $actor->id);
        }

        $query
            ->when($this->filled($filters, 'action'), fn ($builder) => $builder->where('action', trim($filters['action'])))
            ->when($this->filled($filters, 'subject_type'), fn ($builder) => $builder->where('subject_type', trim($filters['subject_type'])))
            ->when($this->filled($filters, 'subject_id'), fn ($builder) => $builder->where('subject_id', trim($filters['subject_id'])))
            ->when($this->filled($filters, 'request_id'), fn ($builder) => $builder->where('request_id', trim($filters['request_id'])))
            ->when($this->filled($filters, 'actor_id'), function ($builder) use ($filters): void {
                $actorId = trim($filters['actor_id']);
                $builder->whereHas('adminUser', fn ($admins) => $admins->where('public_id', $actorId));
            })
            ->when($this->filled($filters, 'actor'), function ($builder) use ($filters): void {
                $keyword = trim($filters['actor']);
                $builder->whereHas('adminUser', function ($admins) use ($keyword): void {
                    $admins->where(function ($nested) use ($keyword): void {
                        $nested->where('public_id', 'like', "%{$keyword}%")
                            ->orWhere('account', 'like', "%{$keyword}%")
                            ->orWhere('display_name', 'like', "%{$keyword}%");
                    });
                });
            });

        $startedAt = $filters['started_at'] ?? $filters['date_from'] ?? null;
        $endedAt = $filters['ended_at'] ?? $filters['date_to'] ?? null;
        if (is_string($startedAt) && $startedAt !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($startedAt)->startOfDay());
        }
        if (is_string($endedAt) && $endedAt !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($endedAt)->endOfDay());
        }

        $pageSize = (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20);
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($pageSize);

        $items = collect($paginator->items())->map(function (OperationLog $log) use ($redactor): array {
            $logActor = $log->adminUser;

            return [
                'id' => $log->public_id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'actor' => $logActor === null ? null : [
                    'id' => $logActor->public_id,
                    'display_name' => $logActor->display_name,
                    'account' => $logActor->account,
                ],
                'before' => $redactor->redact($log->before_value),
                'after' => $redactor->redact($log->after_value),
                'reason' => $log->reason,
                'request_id' => $log->request_id,
                'ip_fingerprint' => $log->ip_hash ? substr($log->ip_hash, 0, 12) : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'scope' => $canViewAll ? 'all' : 'own',
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function filled(array $values, string $key): bool
    {
        return isset($values[$key]) && is_string($values[$key]) && trim($values[$key]) !== '';
    }
}
