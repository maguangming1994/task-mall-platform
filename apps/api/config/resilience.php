<?php

return [
    'slow_request_ms' => (int) env('SLOW_REQUEST_MS', 500),
    'readiness_cache_ttl_seconds' => (int) env('READINESS_CACHE_TTL_SECONDS', 5),
    'pending_delivery_grace_minutes' => (int) env('PENDING_DELIVERY_GRACE_MINUTES', 15),
];
