<?php

namespace App\Modules\Campus\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesAdminCampusThroughRelation
{
    public static function bootScopesAdminCampusThroughRelation(): void
    {
        static::addGlobalScope('admin_campus_relation', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            $campusId = $request->attributes->get('campus_database_id');
            if ($request->attributes->get('campus_context_strict') !== true || ! is_int($campusId)) {
                return;
            }

            $builder->whereHas(static::adminCampusRelation(), function (Builder $related) use ($campusId): void {
                $related->where($related->qualifyColumn('campus_id'), $campusId);
            });
        });
    }

    abstract protected static function adminCampusRelation(): string;
}
