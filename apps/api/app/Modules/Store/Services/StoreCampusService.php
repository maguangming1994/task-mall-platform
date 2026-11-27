<?php

namespace App\Modules\Store\Services;

use App\Modules\Campus\Models\Campus;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreCampus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StoreCampusService
{
    /**
     * 解析业务要操作的校区：未指定时回退门店主校区。
     *
     * @param  string|null  $campusPublicId  校区 public_id
     * @param  bool  $requireActive  库存调整、加购、下单等交易动作要求校区处于开通状态
     */
    public function resolve(Store $store, ?string $campusPublicId, bool $requireActive = true): int
    {
        $campusPublicId = trim((string) $campusPublicId);
        if ($campusPublicId === '') {
            if ($store->campus_id === null) {
                throw ValidationException::withMessages(['campus_id' => ['门店尚未配置开通校区，请联系平台运营。']]);
            }

            return (int) $store->campus_id;
        }

        $campusId = Campus::query()->where('public_id', $campusPublicId)->value('id');
        $link = $campusId === null ? null : StoreCampus::query()
            ->where('store_id', $store->id)
            ->where('campus_id', $campusId)
            ->first();
        if ($link === null || ($requireActive && ! $link->isActive())) {
            throw ValidationException::withMessages(['campus_id' => ['该校区尚未开通或已停用此门店。']]);
        }

        return (int) $link->campus_id;
    }

    /** 消费者侧请求校区，缺省回退主校区（兼容未绑定校区的历史请求）。 */
    public function contextCampusId(Store $store): ?int
    {
        $campusId = app()->bound('request') ? request()->attributes->get('campus_database_id') : null;

        return is_int($campusId) ? $campusId : ($store->campus_id === null ? null : (int) $store->campus_id);
    }

    /** 交易动作（加购/下单）前校验门店已在该校区开通。 */
    public function assertOpen(Store $store, ?int $campusId): int
    {
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;
        if ($campusId === null) {
            throw ValidationException::withMessages(['campus_id' => ['门店尚未配置开通校区，请联系平台运营。']]);
        }
        $open = StoreCampus::query()
            ->where('store_id', $store->id)
            ->where('campus_id', $campusId)
            ->where('status', StoreCampus::STATUS_ACTIVE)
            ->exists();
        if (! $open) {
            throw ValidationException::withMessages(['campus_id' => ['该校区尚未开通或已停用此门店。']]);
        }

        return $campusId;
    }

    /** 平台配置门店开通校区；重复开通视为启用，主校区不可停用。 */
    public function setStatus(Store $store, Campus $campus, string $action, int $adminId): StoreCampus
    {
        if ($action === 'disable' && (int) $store->campus_id === (int) $campus->id) {
            throw ValidationException::withMessages(['campus_id' => ['主校区不可停用，如需停业请使用门店停用。']]);
        }
        if (! in_array($action, ['enable', 'disable'], true)) {
            throw ValidationException::withMessages(['action' => ['不支持的校区操作。']]);
        }

        $link = StoreCampus::query()->firstOrNew(['store_id' => $store->id, 'campus_id' => $campus->id]);
        $link->status = $action === 'enable' ? StoreCampus::STATUS_ACTIVE : StoreCampus::STATUS_DISABLED;
        $link->created_by_admin_id ??= $adminId;
        $link->save();

        return $link->fresh('campus');
    }

    /** @return Collection<int, StoreCampus> */
    public function links(Store $store, bool $onlyActive = false): Collection
    {
        return StoreCampus::query()
            ->where('store_id', $store->id)
            ->when($onlyActive, fn (Builder $query) => $query->where('status', StoreCampus::STATUS_ACTIVE))
            ->with('campus')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    public function present(StoreCampus $link, Store $store): array
    {
        return [
            'campus_id' => $link->campus?->public_id,
            'campus_name' => $link->campus?->name,
            'status' => $link->status,
            'is_primary' => (int) $link->campus_id === (int) $store->campus_id,
        ];
    }

    /**
     * 订单校区过滤：主校区兼容 campus_id 为 NULL 的历史订单。
     *
     * @param  Builder<\App\Modules\Order\Models\Order>  $query
     */
    public function applyOrderCampus(Builder $query, Store $store, int $campusId): void
    {
        $query->where(function (Builder $inner) use ($store, $campusId): void {
            $inner->where('campus_id', $campusId);
            if ((int) $store->campus_id === $campusId) {
                $inner->orWhereNull('campus_id');
            }
        });
    }
}
