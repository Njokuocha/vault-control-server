<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\PersonGalleryCategory;
use App\Models\PersonImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class GalleryCategoryAccess
{
    public function isAdministrator(Request $request): bool
    {
        if ($request->user('admin') instanceof Admin) {
            return true;
        }

        $staff = $request->user('web');
        $configuredAdminEmail = strtolower(trim((string) config('vault.admin.email')));

        return $staff instanceof User
            && $configuredAdminEmail !== ''
            && strtolower($staff->email) === $configuredAdminEmail
            && $staff->department === null
            && in_array($staff->account_status, ['active', null], true);
    }

    public function canViewPersonCategory(PersonGalleryCategory $category, Request $request): bool
    {
        if (! $category->is_locked || $this->isAdministrator($request)) {
            return true;
        }

        $staff = $request->user('web');

        return $staff instanceof User
            && $staff->department === 'pm'
            && $staff->account_status === 'active'
            && $category->authorizedStaff()->whereKey($staff->getKey())->exists();
    }

    public function canViewCaseCategory(CaseCategory $category, Request $request): bool
    {
        if (! $category->is_locked || $this->isAdministrator($request)) {
            return true;
        }

        $staff = $request->user('web');

        return $staff instanceof User
            && $staff->department === 'cfm'
            && $staff->account_status === 'active'
            && $category->authorizedStaff()->whereKey($staff->getKey())->exists();
    }

    public function visiblePersonCategoryIds(int $personId, Request $request): array
    {
        $query = PersonGalleryCategory::query()->where('person_id', $personId);
        if ($this->isAdministrator($request)) {
            return $query->pluck('id')->all();
        }

        $staff = $request->user('web');
        $query->where(function (Builder $categories) use ($staff): void {
            $categories->where('is_locked', false);
            if ($staff instanceof User && $staff->department === 'pm' && $staff->account_status === 'active') {
                $categories->orWhereHas('authorizedStaff', fn (Builder $users) => $users->whereKey($staff->getKey()));
            }
        });

        return $query->pluck('id')->all();
    }

    public function visibleCaseCategoryIds(int $caseId, Request $request): array
    {
        $query = CaseCategory::query()->where('case_file_id', $caseId);
        if ($this->isAdministrator($request)) {
            return $query->pluck('id')->all();
        }

        $staff = $request->user('web');
        $query->where(function (Builder $categories) use ($staff): void {
            $categories->where('is_locked', false);
            if ($staff instanceof User && $staff->department === 'cfm' && $staff->account_status === 'active') {
                $categories->orWhereHas('authorizedStaff', fn (Builder $users) => $users->whereKey($staff->getKey()));
            }
        });

        return $query->pluck('id')->all();
    }

    public function canViewPersonImage(PersonImage $image, Request $request): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        $hasCategory = $image->galleryCategories()->exists()
            || $image->gallery_category_id !== null;
        if (! $hasCategory) {
            return true;
        }

        $staff = $request->user('web');
        $categories = PersonGalleryCategory::query()
            ->where('person_id', $image->person_id)
            ->where(function (Builder $query) use ($image): void {
                $query->whereKey($image->gallery_category_id)
                    ->orWhereHas('images', fn (Builder $images) => $images->whereKey($image->getKey()));
            })
            ->where(function (Builder $query) use ($staff): void {
                $query->where('is_locked', false);
                if ($staff instanceof User && $staff->department === 'pm' && $staff->account_status === 'active') {
                    $query->orWhereHas('authorizedStaff', fn (Builder $users) => $users->whereKey($staff->getKey()));
                }
            });

        return $categories->exists();
    }

    public function canViewCaseImage(CaseImage $image, Request $request): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        $staff = $request->user('web');

        return CaseCategory::query()
            ->where(function (Builder $query) use ($image): void {
                $query->whereKey($image->case_category_id)
                    ->orWhereHas('assignedImages', fn (Builder $images) => $images->whereKey($image->getKey()));
            })
            ->where(function (Builder $query) use ($staff): void {
                $query->where('is_locked', false);
                if ($staff instanceof User && $staff->department === 'cfm' && $staff->account_status === 'active') {
                    $query->orWhereHas('authorizedStaff', fn (Builder $users) => $users->whereKey($staff->getKey()));
                }
            })
            ->exists();
    }
}
