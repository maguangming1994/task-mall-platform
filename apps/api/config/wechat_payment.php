<?php

return [
    'driver' => env('WECHAT_PAYMENT_DRIVER', 'local'),
    'merchant_id' => env('WECHAT_PAYMENT_MERCHANT_ID'),
    'merchant_certificate_serial' => env('WECHAT_PAYMENT_CERT_SERIAL'),
    'merchant_private_key_path' => env('WECHAT_PAYMENT_PRIVATE_KEY_PATH'),
    'api_v3_key' => env('WECHAT_PAYMENT_API_V3_KEY'),
    'platform_certificate_path' => env('WECHAT_PAYMENT_PLATFORM_CERT_PATH'),
    'notify_url' => env('WECHAT_PAYMENT_NOTIFY_URL'),
    'refund_notify_url' => env('WECHAT_PAYMENT_REFUND_NOTIFY_URL'),
    'timeout_seconds' => (int) env('WECHAT_PAYMENT_TIMEOUT_SECONDS', 8),
    'mini_app_id' => env('WECHAT_MINI_APP_ID'),
    'official_account_app_id' => env('WECHAT_OA_APP_ID'),
];
