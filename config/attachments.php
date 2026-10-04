<?php

return [
    'disk' => env('ATTACHMENT_DISK', 'attachments'),
    'max_file_bytes' => 10 * 1024 * 1024,
    'max_per_request' => 20,
    'scanner' => [
        'driver' => env('ATTACHMENT_SCANNER', 'clamav'),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'connect_timeout' => (float) env('CLAMAV_CONNECT_TIMEOUT', 5),
        'read_timeout' => (float) env('CLAMAV_READ_TIMEOUT', 30),
        'chunk_bytes' => 64 * 1024,
    ],
    'allowed_types' => [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv'],
    ],
];
