<?php

namespace App\Modules\Campus\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesAdminCampusCommunityContent
{
    public static function bootScopesAdminCampusCommunityContent(): void
    {
        static::addGlobalScope('admin_campus_content', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            $campusId = $request->attributes->get('campus_database_id');
            if ($request->attributes->get('campus_context_strict') !== true || ! is_int($campusId)) {
                return;
            }

            $typeColumn = $builder->qualifyColumn('content_type');
            $idColumn = $builder->qualifyColumn('content_id');
            $builder->where(function (Builder $content) use ($campusId, $typeColumn, $idColumn): void {
                $content->where(function (Builder $posts) use ($campusId, $typeColumn, $idColumn): void {
                    $posts->where($typeColumn, 'post')
                        ->whereExists(function ($query) use ($campusId, $idColumn): void {
                            $query->selectRaw('1')
                                ->from('community_posts')
                                ->whereColumn('community_posts.id', $idColumn)
                                ->where('community_posts.campus_id', $campusId);
                        });
                })->orWhere(function (Builder $comments) use ($campusId, $typeColumn, $idColumn): void {
                    $comments->where($typeColumn, 'comment')
                        ->whereExists(function ($query) use ($campusId, $idColumn): void {
                            $query->selectRaw('1')
                                ->from('community_comments')
                                ->join('community_posts', 'community_posts.id', '=', 'community_comments.community_post_id')
                                ->whereColumn('community_comments.id', $idColumn)
                                ->where('community_posts.campus_id', $campusId);
                        });
                });
            });
        });
    }
}
