<?php

return [
    'driver' => env('WECHAT_AUTH_DRIVER', 'local'),
    'timeout_seconds' => (int) env('WECHAT_AUTH_TIMEOUT_SECONDS', 5),
    'mini' => [
        'app_id' => env('WECHAT_MINI_APP_ID'),
        'app_secret' => env('WECHAT_MINI_APP_SECRET'),
    ],
    'official_account' => [
        'app_id' => env('WECHAT_OA_APP_ID'),
        'app_secret' => env('WECHAT_OA_APP_SECRET'),
        'token' => env('WECHAT_OA_TOKEN'),
        'encoding_aes_key' => env('WECHAT_OA_ENCODING_AES_KEY'),
    ],
];
