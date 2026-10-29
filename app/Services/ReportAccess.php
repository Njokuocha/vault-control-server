<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class ReportAccess
{
    public function actor(): Authenticatable
    {
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();
        abort_unless(
            $actor instanceof Admin || $this->isStaff($actor) || $this->isLegacyAdmin($actor),
            403,
        );

        return $actor;
    }

    public function isAdministrator(?Authenticatable $actor = null): bool
    {
        $actor ??= Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        return $actor instanceof Admin || $this->isLegacyAdmin($actor);
    }

    public function isStaff(?Authenticatable $actor = null): bool
    {
        $actor ??= Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        return $actor instanceof User
            && in_array($actor->department, ['cfm', 'pm', 'fm'], true)
            && $actor->account_status === 'active';
    }

    public function canView(Report $report, ?Authenticatable $actor = null): bool
    {
        $actor ??= $this->actor();

        return $this->isAdministrator($actor)
            || ! $report->is_hidden
            || ($report->reporter_type === $actor::class && (int) $report->reporter_id === (int) $actor->getAuthIdentifier());
    }

    public function canManage(Report $report, ?Authenticatable $actor = null): bool
    {
        $actor ??= $this->actor();

        return $this->isAdministrator($actor)
            || ($report->reporter_type === $actor::class && (int) $report->reporter_id === (int) $actor->getAuthIdentifier());
    }

    public function canLinkCases(?Authenticatable $actor = null): bool
    {
        $actor ??= $this->actor();

        return $this->isAdministrator($actor) || ($actor instanceof User && $actor->department === 'cfm');
    }

    public function canLinkPeople(?Authenticatable $actor = null): bool
    {
        $actor ??= $this->actor();

        return $this->isAdministrator($actor) || ($actor instanceof User && $actor->department === 'pm');
    }

    public function visibleQuery(?Authenticatable $actor = null)
    {
        $actor ??= $this->actor();
        $query = Report::query();

        if ($this->isAdministrator($actor)) {
            return $query;
        }

        return $query->where(function ($query) use ($actor): void {
            $query->where('is_hidden', false)
                ->orWhere(function ($query) use ($actor): void {
                    $query->where('reporter_type', $actor::class)
                        ->where('reporter_id', $actor->getAuthIdentifier());
                });
        });
    }

    private function isLegacyAdmin(?Authenticatable $actor): bool
    {
        $adminEmail = strtolower((string) config('vault.admin.email'));

        return $actor instanceof User
            && $actor->department === null
            && $adminEmail !== ''
            && hash_equals($adminEmail, strtolower((string) $actor->email));
    }
}
