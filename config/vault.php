<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@vault.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'initial_upload_key' => env('INITIAL_UPLOAD_KEY'),
];
