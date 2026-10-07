<?php

namespace App\Modules\Campus\Models\Concerns;

use App\Modules\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCampus
{
    public static function bootBelongsToCampus(): void
    {
        static::addGlobalScope('campus_context', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }
            $request = request();
            $campusId = $request->attributes->get('campus_database_id');
            if (! is_int($campusId)) {
                // strict 模式下无校区上下文：fail-close 空集
                if ($request->attributes->get('campus_strict', false)) {
                    $model = $builder->getModel();
                    if ($model instanceof Product) {
                        // D4：商品目录一期平台统一，strict 下不隔离
                        return;
                    }
                    if (method_exists($model, 'applyCampusScope')) {
                        $model->applyCampusScope($builder, null);
                    } else {
                        $builder->whereRaw('1 = 0');
                    }
                }

                return;
            }

            // legacy：完全保留现状（NULL 或当前校）
            if (! $request->attributes->get('campus_strict', false)) {
                $builder->where(function (Builder $query) use ($campusId): void {
                    $query->whereNull($query->qualifyColumn('campus_id'))
                        ->orWhere($query->qualifyColumn('campus_id'), $campusId);
                });

                return;
            }

            // strict：D3 UGC 无 NULL 兜底，仅显式豁免模型可跨校
            $model = $builder->getModel();
            if ($model instanceof Product) {
                // D4：商品目录一期平台统一，strict 下不隔离
                return;
            }
            if (method_exists($model, 'applyCampusScope')) {
                $model->applyCampusScope($builder, $campusId);
            } else {
                $builder->where($builder->qualifyColumn('campus_id'), $campusId);
            }
        });

        static::creating(function ($model): void {
            if ($model->campus_id !== null || ! app()->bound('request')) {
                return;
            }
            $campusId = request()->attributes->get('campus_database_id');
            if (is_int($campusId)) {
                $model->campus_id = $campusId;
            }
        });
    }
}
