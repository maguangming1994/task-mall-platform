<?php

return [
    'session_ttl_seconds' => (int) env('PUBLISHER_AUTH_SESSION_TTL_SECONDS', 1800),
    'hash_secret' => env('PUBLISHER_AUTH_HASH_KEY') ?: env('ADMIN_AUTH_HASH_KEY') ?: env('APP_KEY'),
];
