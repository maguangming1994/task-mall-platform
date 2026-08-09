<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174')))));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-Id', 'X-Wechat-*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 600,
    'supports_credentials' => false,
];
