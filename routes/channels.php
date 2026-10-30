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
    return $user instanceof User && (int) $user->getAuthIdentifier() === $userId;
});

Broadcast::channel('admin.uploads.{adminId}', function ($user, int $adminId): bool {
    return $user instanceof Admin && (int) $user->getAuthIdentifier() === $adminId;
});

Broadcast::channel('reports.agency', function ($user): bool {
    if ($user instanceof Admin) {
        return true;
    }

    if (! ($user instanceof User)) {
        return false;
    }

    if (in_array($user->department, ['cfm', 'pm', 'fm'], true) && $user->account_status === 'active') {
        return true;
    }

    $adminEmail = strtolower((string) config('vault.admin.email'));

    return $user->department === null
        && $adminEmail !== ''
        && hash_equals($adminEmail, strtolower((string) $user->email));
});
