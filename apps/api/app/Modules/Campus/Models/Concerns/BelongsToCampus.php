<?php

namespace App\Modules\Campus\Models\Concerns;

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
                return;
            }

            if ($request->attributes->get('campus_context_strict', false)) {
                $builder->where($builder->qualifyColumn('campus_id'), $campusId);

                return;
            }

            $builder->where(function (Builder $query) use ($campusId): void {
                $query->whereNull($query->qualifyColumn('campus_id'))
                    ->orWhere($query->qualifyColumn('campus_id'), $campusId);
            });
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
