<?php

return [
    'shell_root' => storage_path('app/private/vault'),
    'max_upload_mb' => (int) env('VAULT_MAX_UPLOAD_MB', 100),
    'max_video_upload_mb' => (int) env('VAULT_MAX_VIDEO_MB', 0),
    'chunk_size_mb' => (int) env('VAULT_CHUNK_SIZE_MB', 5),
    'sendfile' => strtolower((string) env('VAULT_SENDFILE', 'off')),
    'ffmpeg_path' => env('VAULT_FFMPEG_PATH'),
    'ffprobe_path' => env('VAULT_FFPROBE_PATH'),
    'archive_max_entries' => 10000,
    'archive_max_uncompressed_bytes' => 2147483648,
    'archive_max_compression_ratio' => 1000,
    'api_upload_enabled' => (bool) env('VAULT_API_UPLOAD_ENABLED', true),
    'upload_disk' => 'vault',
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@vault.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'initial_upload_key' => env('INITIAL_UPLOAD_KEY'),
];
