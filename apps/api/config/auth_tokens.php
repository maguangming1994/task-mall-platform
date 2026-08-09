<?php

return [
    'issuer' => env('AUTH_TOKEN_ISSUER', 'task-mall-api'),
    'audience' => 'task-mall-user',
    'jwt_secret' => env('AUTH_JWT_SECRET') ?: env('APP_KEY'),
    'identity_hash_secret' => env('AUTH_IDENTITY_HASH_KEY') ?: env('APP_KEY'),
    'access_ttl_seconds' => (int) env('AUTH_ACCESS_TTL_SECONDS', 7200),
    'refresh_ttl_days' => (int) env('AUTH_REFRESH_TTL_DAYS', 30),
    'max_active_devices' => (int) env('AUTH_MAX_ACTIVE_DEVICES', 5),
    'device_limit_strategy' => env('AUTH_DEVICE_LIMIT_STRATEGY', 'revoke_oldest'),
    'phone_verification' => [
        'driver' => env('PHONE_VERIFICATION_DRIVER', 'log'),
        'ttl_seconds' => (int) env('PHONE_VERIFICATION_TTL_SECONDS', 300),
        'development_code' => env('PHONE_VERIFICATION_DEV_CODE', '246810'),
        'webhook' => [
            'endpoint' => env('SMS_WEBHOOK_ENDPOINT'),
            'token' => env('SMS_WEBHOOK_TOKEN'),
            'template_id' => env('SMS_TEMPLATE_ID'),
            'sign_name' => env('SMS_SIGN_NAME'),
            'timeout_seconds' => (int) env('SMS_TIMEOUT_SECONDS', 5),
        ],
    ],
];
