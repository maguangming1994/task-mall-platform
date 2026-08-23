<?php

return [
    'driver' => env('PAYOUT_DRIVER', 'local'),
    'endpoint' => env('PAYOUT_ENDPOINT'),
    'secret' => env('PAYOUT_SECRET'),
    'callback_url' => env('PAYOUT_CALLBACK_URL'),
    'timeout_seconds' => (int) env('PAYOUT_TIMEOUT_SECONDS', 8),
];
