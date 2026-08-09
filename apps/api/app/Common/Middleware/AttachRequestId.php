<?php

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Request-Id');
        $requestId = is_string($provided) && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $provided)
            ? $provided
            : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
