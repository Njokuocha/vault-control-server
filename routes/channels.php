<?php

use App\Models\Admin;
use App\Models\InvestigationCase;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportAccess;
use App\Support\CaseAnalysisAccess;
use Illuminate\Support\Facades\Broadcast;

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
    return $user instanceof User
        && $user->department === 'fm'
        && $user->account_status === 'active';
});

Broadcast::channel('uploads.{userId}', function ($user, int $userId): bool {
    return $user instanceof User && (int) $user->getAuthIdentifier() === $userId;
});

Broadcast::channel('case-analysis.{caseId}', function ($user, int $caseId): bool {
    $access = app(CaseAnalysisAccess::class);
    if (! $access->isAdministrator($user) && ! $access->isCfmStaff($user)) {
        return false;
    }

    return InvestigationCase::query()->whereKey($caseId)->exists();
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

Broadcast::channel('reports.control', function ($user): bool {
    return app(ReportAccess::class)->isAdministrator($user);
});

Broadcast::channel('reports.{reportId}', function ($user, int $reportId): bool {
    $access = app(ReportAccess::class);
    if (! $access->isAdministrator($user) && ! $access->isStaff($user)) {
        return false;
    }

    $report = Report::query()->find($reportId);

    return $report !== null && $access->canView($report, $user);
});
