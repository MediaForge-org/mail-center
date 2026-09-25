<?php

return [
    'credentials' => [
        'key' => env('MAIL_CREDENTIALS_KEY'),
        'key_id' => env('MAIL_CREDENTIALS_KEY_ID', 'v1'),
        'previous_keys' => json_decode((string) env('MAIL_CREDENTIALS_PREVIOUS_KEYS', '{}'), true) ?: [],
    ],
    'blobs' => [
        'root' => env('MAIL_BLOB_ROOT'),
    ],
    'imap' => [
        'allowed_ports' => [143, 993],
        'private_allowlist' => array_filter(explode(',', (string) env('MAIL_IMAP_PRIVATE_ALLOWLIST', ''))),
        'timeout_seconds' => 30,
        'max_message_bytes' => 10 * 1024 * 1024,
        'uid_window' => 10000,
        'uids_per_batch' => 100,
        'work_seconds' => 240,
        'flag_scan_seconds' => 900,
        'removal_grace_seconds' => 86400,
    ],
];
