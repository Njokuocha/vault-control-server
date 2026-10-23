<?php

return [
    'shell_root' => storage_path('app/private/vault'),
    'max_upload_mb' => (int) env('VAULT_MAX_UPLOAD_MB', 100),
    'api_upload_enabled' => (bool) env('VAULT_API_UPLOAD_ENABLED', true),
    'upload_disk' => 'vault',
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@vault.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'initial_upload_key' => env('INITIAL_UPLOAD_KEY'),
];
