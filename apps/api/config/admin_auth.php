<?php

return [
    'issuer' => env('AUTH_TOKEN_ISSUER', 'task-mall-api'),
    'audience' => 'task-mall-admin',
    'jwt_secret' => env('ADMIN_AUTH_JWT_SECRET') ?: env('AUTH_JWT_SECRET') ?: env('APP_KEY'),
    'hash_secret' => env('ADMIN_AUTH_HASH_KEY') ?: env('AUTH_IDENTITY_HASH_KEY') ?: env('APP_KEY'),
    'access_ttl_seconds' => (int) env('ADMIN_AUTH_ACCESS_TTL_SECONDS', 3600),
    'refresh_ttl_days' => (int) env('ADMIN_AUTH_REFRESH_TTL_DAYS', 7),
    'idle_ttl_seconds' => (int) env('ADMIN_AUTH_IDLE_TTL_SECONDS', 1800),
    'remember_refresh_ttl_days' => (int) env('ADMIN_AUTH_REMEMBER_REFRESH_TTL_DAYS', 30),
    'remember_idle_ttl_seconds' => (int) env('ADMIN_AUTH_REMEMBER_IDLE_TTL_SECONDS', 2592000),
    'remember_cookie_name' => env('ADMIN_AUTH_REMEMBER_COOKIE_NAME', 'task_mall_admin_refresh'),
    'max_active_sessions' => (int) env('ADMIN_AUTH_MAX_ACTIVE_SESSIONS', 3),
    'wechat_binding_ttl_days' => (int) env('ADMIN_AUTH_WECHAT_BINDING_TTL_DAYS', 180),
    'failure_threshold' => (int) env('ADMIN_AUTH_FAILURE_THRESHOLD', 5),
    'failure_window_seconds' => (int) env('ADMIN_AUTH_FAILURE_WINDOW_SECONDS', 900),
    'lock_seconds' => (int) env('ADMIN_AUTH_LOCK_SECONDS', 900),
    'recent_verification_seconds' => (int) env('ADMIN_AUTH_RECENT_VERIFICATION_SECONDS', 900),
];
