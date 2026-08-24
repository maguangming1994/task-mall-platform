<?php

return [
    'mode' => env('UPLOAD_MODE', env('APP_ENV') === 'production' ? 's3_presigned' : 'local_proxy'),
    'disk' => env('UPLOAD_DISK', env('FILESYSTEM_DISK', 'local')),
    'ticket_ttl_seconds' => (int) env('UPLOAD_TICKET_TTL_SECONDS', 900),
    'scanner' => env('UPLOAD_SCANNER', env('APP_ENV') === 'production' ? 'required_external' : 'local_safe'),
    'scanner_webhook' => [
        'endpoint' => env('UPLOAD_SCANNER_ENDPOINT'),
        'token' => env('UPLOAD_SCANNER_TOKEN'),
        'timeout_seconds' => (int) env('UPLOAD_SCANNER_TIMEOUT_SECONDS', 20),
    ],
    'allowed' => [
        'avatar' => ['categories' => ['image'], 'max_bytes' => 10 * 1024 * 1024],
        'task_submission' => ['categories' => ['image', 'video', 'document'], 'max_bytes' => 100 * 1024 * 1024],
        'task_report' => ['categories' => ['image', 'document'], 'max_bytes' => 20 * 1024 * 1024],
        'community' => ['categories' => ['image', 'video'], 'max_bytes' => 100 * 1024 * 1024],
        'refund_evidence' => ['categories' => ['image', 'document'], 'max_bytes' => 20 * 1024 * 1024],
        'return_shipping' => ['categories' => ['image', 'document'], 'max_bytes' => 20 * 1024 * 1024],
        'offline_recharge' => ['categories' => ['image'], 'max_bytes' => 20 * 1024 * 1024],
    ],
    'extensions' => [
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'video' => ['mp4', 'mov', 'webm'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
    ],
    'mimes' => [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm'],
        'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain', 'application/zip'],
    ],
];
