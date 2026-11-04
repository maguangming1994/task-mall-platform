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
    // 审核演示登录账号：仅在审核期（review_in_progress）可用，用于微信审核员体验登录、闲置与任务发布。
    'review_demo' => [
        'phone' => env('REVIEW_DEMO_PHONE', '13800000001'),
        'code' => env('REVIEW_DEMO_CODE', '246810'),
    ],
    'phone_verification' => [
        'driver' => env('PHONE_VERIFICATION_DRIVER', 'log'),
        'ttl_seconds' => (int) env('PHONE_VERIFICATION_TTL_SECONDS', 300),
        'development_code' => env('PHONE_VERIFICATION_DEV_CODE', '246810'),
        'max_attempts' => (int) env('PHONE_VERIFICATION_MAX_ATTEMPTS', 5),
        'resend_interval_seconds' => (int) env('PHONE_VERIFICATION_RESEND_SECONDS', 60),
        'hourly_limit' => (int) env('PHONE_VERIFICATION_HOURLY_LIMIT', 5),
        'daily_limit' => (int) env('PHONE_VERIFICATION_DAILY_LIMIT', 10),
        'aliyun' => [
            'access_key_id' => env('ALIBABA_CLOUD_ACCESS_KEY_ID'),
            'access_key_secret' => env('ALIBABA_CLOUD_ACCESS_KEY_SECRET'),
            'security_token' => env('ALIBABA_CLOUD_SECURITY_TOKEN'),
            'region_id' => env('ALIYUN_SMS_REGION_ID', 'cn-hangzhou'),
            'endpoint' => env('ALIYUN_SMS_ENDPOINT', 'dysmsapi.aliyuncs.com'),
            'sign_name' => env('ALIYUN_SMS_SIGN_NAME'),
            'template_code' => env('ALIYUN_SMS_TEMPLATE_CODE'),
            'code_variable' => env('ALIYUN_SMS_CODE_VARIABLE', 'code'),
            'timeout_milliseconds' => (int) env('ALIYUN_SMS_TIMEOUT_MILLISECONDS', 5000),
        ],
        'webhook' => [
            'endpoint' => env('SMS_WEBHOOK_ENDPOINT'),
            'token' => env('SMS_WEBHOOK_TOKEN'),
            'template_id' => env('SMS_TEMPLATE_ID'),
            'sign_name' => env('SMS_SIGN_NAME'),
            'timeout_seconds' => (int) env('SMS_TIMEOUT_SECONDS', 5),
        ],
    ],
];
