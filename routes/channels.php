<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Admin;
use App\Models\User;

Broadcast::channel('admin.vault', function ($user): bool {
    $adminEmail = strtolower((string) config('vault.admin.email'));

    return $adminEmail !== ''
        && ($user instanceof Admin || ($user instanceof User && $user->department === null))
        && hash_equals($adminEmail, strtolower((string) $user->email));
});

Broadcast::channel('admin.security', function ($user): bool {
    $adminEmail = strtolower((string) config('vault.admin.email'));

    return $adminEmail !== ''
        && ($user instanceof Admin || ($user instanceof User && $user->department === null))
        && hash_equals($adminEmail, strtolower((string) $user->email));
});

Broadcast::channel('fm.vault', function ($user): bool {
    return $user instanceof \App\Models\User
        && $user->department === 'fm'
        && $user->account_status === 'active';
});

Broadcast::channel('uploads.{userId}', function ($user, int $userId): bool {
    return (int) $user->getAuthIdentifier() === $userId;
});
