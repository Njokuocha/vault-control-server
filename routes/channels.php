<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin.vault', function ($user): bool {
    $adminEmail = strtolower((string) config('vault.admin.email'));

    return $adminEmail !== '' && hash_equals($adminEmail, strtolower((string) $user->email));
});

Broadcast::channel('uploads.{userId}', function ($user, int $userId): bool {
    return (int) $user->getAuthIdentifier() === $userId;
});
