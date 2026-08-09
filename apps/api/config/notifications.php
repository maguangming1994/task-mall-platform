<?php

return [
    'driver' => env('NOTIFICATION_EXTERNAL_DRIVER', env('APP_ENV') === 'production' ? 'webhook' : 'local'),
    'webhook' => ['endpoint' => env('NOTIFICATION_WEBHOOK_ENDPOINT'), 'token' => env('NOTIFICATION_WEBHOOK_TOKEN'), 'timeout_seconds' => (int) env('NOTIFICATION_WEBHOOK_TIMEOUT_SECONDS', 10)],
];
