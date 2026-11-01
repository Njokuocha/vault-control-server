<?php

namespace App\Http\Controllers;

use App\Models\CaseCategory;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonGalleryCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\GalleryCategoryAccess;
use App\Support\VersionedCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GalleryCategoryAccessController extends Controller
{
    public function personStaff(
        Request $request,
        Person $person,
        PersonGalleryCategory $category,
        GalleryCategoryAccess $access,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->person_id === $person->getKey(), 404);

        return $this->staffResponse($request, $category, 'pm');
    }

    public function caseStaff(
        Request $request,
        InvestigationCase $caseFile,
        CaseCategory $category,
        GalleryCategoryAccess $access,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->case_file_id === $caseFile->getKey(), 404);

        return $this->staffResponse($request, $category, 'cfm');
    }

    public function updatePersonLock(
        Request $request,
        Person $person,
        PersonGalleryCategory $category,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->person_id === $person->getKey(), 404);

        return $this->updateLock($request, $category, 'person_gallery_category', $logger, $cache, 'people');
    }

    public function updateCaseLock(
        Request $request,
        InvestigationCase $caseFile,
        CaseCategory $category,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->case_file_id === $caseFile->getKey(), 404);

        return $this->updateLock($request, $category, 'case_category', $logger, $cache, 'cases');
    }

    public function grantPerson(
        Request $request,
        Person $person,
        PersonGalleryCategory $category,
        User $user,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->person_id === $person->getKey(), 404);

        return $this->changeGrant($request, $category, $user, 'pm', true, 'person_gallery_category', $logger, $cache, 'people');
    }

    public function revokePerson(
        Request $request,
        Person $person,
        PersonGalleryCategory $category,
        User $user,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->person_id === $person->getKey(), 404);

        return $this->changeGrant($request, $category, $user, 'pm', false, 'person_gallery_category', $logger, $cache, 'people');
    }

    public function grantCase(
        Request $request,
        InvestigationCase $caseFile,
        CaseCategory $category,
        User $user,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->case_file_id === $caseFile->getKey(), 404);

        return $this->changeGrant($request, $category, $user, 'cfm', true, 'case_category', $logger, $cache, 'cases');
    }

    public function revokeCase(
        Request $request,
        InvestigationCase $caseFile,
        CaseCategory $category,
        User $user,
        GalleryCategoryAccess $access,
        ActivityLogger $logger,
        VersionedCache $cache,
    ): JsonResponse {
        $this->authorizeAdministrator($request, $access);
        abort_unless($category->case_file_id === $caseFile->getKey(), 404);

        return $this->changeGrant($request, $category, $user, 'cfm', false, 'case_category', $logger, $cache, 'cases');
    }

    private function staffResponse(
        Request $request,
        CaseCategory|PersonGalleryCategory $category,
        string $department,
    ): JsonResponse {
        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');
        $staff = User::query()
            ->where('department', $department)
            ->where('account_status', 'active')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'username']);
        $grantedIds = $staff->isEmpty()
            ? []
            : $category->authorizedStaff()->whereIn('users.id', $staff->modelKeys())->pluck('users.id')->all();

        return response()->json([
            'staff' => $staff->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'username' => $user->username,
                'photo_url' => $user->profile_photo_path ? route('admin.staff.photo', $user) : null,
                'granted' => in_array($user->getKey(), $grantedIds, true),
            ])->values(),
            'query' => $search,
        ]);
    }

    private function updateLock(
        Request $request,
        CaseCategory|PersonGalleryCategory $category,
        string $subject,
        ActivityLogger $logger,
        VersionedCache $cache,
        string $cacheNamespace,
    ): JsonResponse {
        $validated = $request->validate(['locked' => ['required', 'boolean']]);
        $locked = (bool) $validated['locked'];
        $revokedIds = [];

        DB::transaction(function () use ($category, $locked, &$revokedIds, $subject, $logger, $request): void {
            $lockedCategory = $category->newQuery()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            if ($locked) {
                $lockedCategory->update(['is_locked' => true]);
            } else {
                $revokedIds = $lockedCategory->authorizedStaff()->pluck('users.id')->all();
                $lockedCategory->authorizedStaff()->detach();
                $lockedCategory->update(['is_locked' => false]);
            }

            $logger->record(
                'gallery_access',
                $locked ? 'category_locked' : 'category_unlocked',
                ($locked ? 'Locked ' : 'Unlocked ').'gallery category: '.$lockedCategory->title,
                ['subject' => $subject, 'category_id' => $lockedCategory->getKey(), 'revoked_staff_ids' => $revokedIds],
                $request->user('admin') ?? $request->user('web'),
                $request,
            );
        });

        $cache->bumpAfterCommit($cacheNamespace);

        return response()->json([
            'locked' => $locked,
            'revoked_count' => count($revokedIds),
            'message' => $locked ? 'Category access is now restricted.' : 'Category unlocked; all staff grants were revoked.',
        ]);
    }

    private function changeGrant(
        Request $request,
        CaseCategory|PersonGalleryCategory $category,
        User $user,
        string $department,
        bool $grant,
        string $subject,
        ActivityLogger $logger,
        VersionedCache $cache,
        string $cacheNamespace,
    ): JsonResponse {
        abort_unless($user->department === $department && $user->account_status === 'active', 404);

        DB::transaction(function () use ($category, $user, $grant, $subject, $logger, $request): void {
            $lockedCategory = $category->newQuery()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            abort_if(! $lockedCategory->is_locked, 409, 'Staff access can only be changed while the category is locked.');
            if ($grant) {
                $lockedCategory->authorizedStaff()->syncWithoutDetaching([$user->getKey()]);
            } else {
                $lockedCategory->authorizedStaff()->detach($user->getKey());
            }

            $logger->record(
                'gallery_access',
                $grant ? 'staff_granted' : 'staff_revoked',
                ($grant ? 'Granted ' : 'Revoked ').$user->name.' access to gallery category: '.$category->title,
                ['subject' => $subject,                 'category_id' => $lockedCategory->getKey(), 'staff_id' => $user->getKey()],
                $request->user('admin') ?? $request->user('web'),
                $request,
            );
        });

        $cache->bumpAfterCommit($cacheNamespace);

        return response()->json([
            'granted' => $grant,
            'staff_id' => $user->getKey(),
            'message' => $grant ? 'Staff access granted.' : 'Staff access revoked.',
        ]);
    }

    private function authorizeAdministrator(Request $request, GalleryCategoryAccess $access): void
    {
        abort_unless($access->isAdministrator($request), 403);
    }
}
