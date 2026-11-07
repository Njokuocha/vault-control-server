<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\CaseAnalysisNote;
use App\Models\InvestigationCase;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class CaseAnalysisAccess
{
    public function actor(): Authenticatable
    {
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();
        abort_unless($this->isAdministrator($actor) || $this->isCfmStaff($actor), 403);

        return $actor;
    }

    public function authorizeCase(InvestigationCase $caseFile, ?Authenticatable $actor = null): void
    {
        $actor ??= $this->actor();
        abort_unless($this->isAdministrator($actor) || $this->isCfmStaff($actor), 404);
    }

    public function isAdministrator(?Authenticatable $actor): bool
    {
        if ($actor instanceof Admin) {
            return true;
        }

        $adminEmail = strtolower((string) config('vault.admin.email'));

        return $actor instanceof User
            && $actor->department === null
            && $adminEmail !== ''
            && hash_equals($adminEmail, strtolower((string) $actor->email));
    }

    public function isCfmStaff(?Authenticatable $actor): bool
    {
        return $actor instanceof User
            && $actor->department === 'cfm'
            && $actor->account_status === 'active';
    }

    public function isNoteAuthor(CaseAnalysisNote $note, Authenticatable $actor): bool
    {
        return $note->author_type === $actor::class
            && (int) $note->author_id === (int) $actor->getAuthIdentifier();
    }
}
