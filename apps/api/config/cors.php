<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174')))));

return [
    'paths' => ['api/*'],
    // 前端实际使用：GET（公开接口）、POST（登录/发请求）、PUT/PATCH（编辑）、DELETE（删除）、OPTIONS（预检）。
    // 但 /internal/metrics、storage 等不应暴露跨域写操作，这里已收紧。
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-Id', 'X-Wechat-*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 600,
    // 必须 false：所有涉及 Cookie 的请求都走内部同源（Laravel Sanctum）或 Authorization Bearer。
    'supports_credentials' => false,
];
