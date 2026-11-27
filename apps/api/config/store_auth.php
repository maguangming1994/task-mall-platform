<?php

return [
    'session_ttl_seconds' => (int) env('STORE_MANAGER_SESSION_TTL_SECONDS', 43200),
    'hash_secret' => env('STORE_MANAGER_HASH_KEY') ?: env('PUBLISHER_AUTH_HASH_KEY') ?: env('ADMIN_AUTH_HASH_KEY') ?: env('APP_KEY'),
];
