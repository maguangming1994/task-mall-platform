<?php

namespace App\Modules\Campus\Http\Middleware;

use App\Modules\Campus\Models\Campus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCampusContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicId = trim((string) $request->header('X-Campus-Id', ''));
        if ($publicId !== '') {
            $campus = Campus::query()->where('public_id', $publicId)->where('status', 'active')->first();
            if ($campus !== null) {
                $request->attributes->set('campus_database_id', $campus->id);
                $request->attributes->set('campus_public_id', $campus->public_id);
            }
        }

        return $next($request);
    }
}
