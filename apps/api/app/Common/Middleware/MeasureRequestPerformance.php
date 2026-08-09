<?php

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MeasureRequestPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $duration = round((hrtime(true) - $started) / 1_000_000, 2);
        $response->headers->set('Server-Timing', 'app;dur='.$duration);

        if ($duration >= (int) config('resilience.slow_request_ms')) {
            Log::warning('slow_api_request', [
                'request_id' => $request->attributes->get('request_id'),
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? 'unmatched',
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
            ]);
        }

        return $response;
    }
}
