<?php

use App\Events\CaseAnalysisUpdated;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CaseAnalysisNote;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\User;
use App\Support\CaseAnalysisCache;
use App\Support\VersionedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createCaseAnalysisCase(string $reference, string $title = 'Analysis case'): InvestigationCase
{
    return InvestigationCase::query()->create([
        'public_id' => $reference,
        'title' => $title,
        'case_status' => 'OPEN',
        'classification' => 'CLASSIFIED',
    ]);
}

function createCaseAnalysisReport(User|Admin $reporter, string $body, bool $hidden = false): Report
{
    return Report::query()->create([
        'uuid' => (string) Str::uuid(),
        'body' => $body,
        'reporter_type' => $reporter::class,
        'reporter_id' => $reporter->getKey(),
        'is_hidden' => $hidden,
    ]);
}

function createCaseAnalysisAdmin(): Admin
{
    return Admin::query()->create([
        'name' => 'Analysis Administrator',
        'email' => 'analysis-admin@example.test',
        'password' => 'a-secure-password',
    ]);
}

test('only administrators and active CFM staff can open the portal', function () {
    $caseManager = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $otherDepartment = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);

    Auth::guard('web')->logout();
    $this->actingAs(createCaseAnalysisAdmin(), 'admin')
        ->get(route('admin.case-analysis.index'))
        ->assertOk()
        ->assertSee('Case Analysis')
        ->assertSee('data-case-analysis', false);

    $this->actingAs($caseManager, 'web')
        ->get(route('cfm.case-analysis.index'))
        ->assertOk()
        ->assertSee('Case Analysis');

    $this->actingAs($otherDepartment, 'web')
        ->get(route('cfm.case-analysis.index'))
        ->assertForbidden();
});

test('case search and diagram endpoints expose only genuine linked records and visible reports', function () {
    $staff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $caseFile = createCaseAnalysisCase('CSE-ANALYSIS-01', 'Northern station investigation');
    $caseFile->update(['created_by_user_id' => $staff->getKey()]);
    $linkedPerson = Person::query()->create(['public_id' => 'USR-ANALYSIS1', 'name' => 'Linked Witness']);
    $unlinkedPerson = Person::query()->create(['public_id' => 'USR-ANALYSIS2', 'name' => 'Unlinked Witness']);
    $caseFile->people()->attach($linkedPerson);
    $visibleReport = createCaseAnalysisReport($staff, 'Visible field report mentioning Unlinked Witness.');
    $visibleReport->cases()->attach($caseFile);
    $hiddenReport = createCaseAnalysisReport($otherStaff, 'Private report content.', true);
    $hiddenReport->cases()->attach($caseFile);

    $this->actingAs($staff, 'web')
        ->getJson(route('cfm.case-analysis.search', ['q' => 'Northern']))
        ->assertOk()
        ->assertJsonPath('items.0.public_id', 'CSE-ANALYSIS-01')
        ->assertJsonPath('items.0.title', 'Northern station investigation');

    $this->getJson(route('cfm.case-analysis.primary', $caseFile))
        ->assertOk()
        ->assertJsonCount(1, 'reports')
        ->assertJsonPath('reports.0.type', 'report')
        ->assertJsonPath('reports.0.title', 'Visible field report mentioning Unlinked Witness.')
        ->assertJsonCount(1, 'people')
        ->assertJsonPath('people.0.title', 'Linked Witness')
        ->assertJsonPath('staff.0.title', $caseFile->creator?->name ?? null)
        ->assertJsonMissing(['title' => 'Unlinked Witness'])
        ->assertJsonMissing(['title' => 'Private report content.']);
});

test('case attachments omit categories restricted to the current CFM user', function () {
    $staff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $caseFile = createCaseAnalysisCase('CSE-ANALYSIS-02');
    $openCategory = $caseFile->categories()->create(['title' => 'Open evidence']);
    $lockedCategory = $caseFile->categories()->create(['title' => 'Restricted evidence', 'is_locked' => true]);
    $openImage = CaseImage::query()->create([
        'case_category_id' => $openCategory->getKey(),
        'path' => 'cases/open/evidence.jpg',
        'original_name' => 'open-evidence.jpg',
        'type' => 'image',
    ]);
    CaseImage::query()->create([
        'case_category_id' => $lockedCategory->getKey(),
        'path' => 'cases/restricted/secret.jpg',
        'original_name' => 'restricted-secret.jpg',
        'type' => 'image',
    ]);

    $this->actingAs($staff, 'web')
        ->getJson(route('cfm.case-analysis.secondary', $caseFile))
        ->assertOk()
        ->assertJsonCount(1, 'attachments')
        ->assertJsonPath('attachments.0.record_id', $openImage->getKey())
        ->assertJsonMissing(['title' => 'restricted-secret.jpg'])
        ->assertJsonMissing(['path' => 'cases/restricted/secret.jpg']);

    $this->actingAs(createCaseAnalysisAdmin(), 'admin')
        ->getJson(route('admin.case-analysis.secondary', $caseFile))
        ->assertOk()
        ->assertJsonCount(2, 'attachments');
});

test('recent imports are user-specific and capped at ten cases', function () {
    $staff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $cases = collect(range(1, 11))->map(fn (int $number): InvestigationCase => createCaseAnalysisCase(
        'CSE-RECENT-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        'Recent case '.$number,
    ));

    foreach ($cases as $caseFile) {
        $this->actingAs($staff, 'web')->postJson(route('cfm.case-analysis.import', $caseFile))->assertOk();
    }

    $this->actingAs($staff, 'web')
        ->getJson(route('cfm.case-analysis.recent'))
        ->assertOk()
        ->assertJsonCount(10, 'items')
        ->assertJsonMissing(['public_id' => 'CSE-RECENT-01']);

    $this->actingAs($otherStaff, 'web')
        ->getJson(route('cfm.case-analysis.recent'))
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

test('analysis notes are shared, author-managed, admin-managed, and protected against stale edits', function () {
    $author = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $caseFile = createCaseAnalysisCase('CSE-NOTES-01');

    $created = $this->actingAs($author, 'web')
        ->postJson(route('cfm.case-analysis.notes.store', $caseFile), ['body' => '<script>not executable</script>'])
        ->assertCreated()
        ->assertJsonPath('note.body', '<script>not executable</script>')
        ->assertJsonPath('note.author', $author->name);
    $noteId = $created->json('note.id');

    $this->actingAs($otherStaff, 'web')
        ->getJson(route('cfm.case-analysis.notes.index', $caseFile))
        ->assertOk()
        ->assertJsonPath('items.0.body', '<script>not executable</script>');
    $this->putJson(route('cfm.case-analysis.notes.update', [$caseFile, $noteId]), [
            'body' => 'Unauthorized edit',
            'version' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($author, 'web')
        ->putJson(route('cfm.case-analysis.notes.update', [$caseFile, $noteId]), [
            'body' => 'Shared revised note',
            'version' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('note.version', 2);

    $this->putJson(route('cfm.case-analysis.notes.update', [$caseFile, $noteId]), [
        'body' => 'Stale write',
        'version' => 1,
    ])->assertStatus(409);

    Auth::guard('web')->logout();
    $admin = createCaseAnalysisAdmin();
    $this->actingAs($admin, 'admin')
        ->putJson(route('admin.case-analysis.notes.update', [$caseFile, $noteId]), [
            'body' => 'Administrator correction',
            'version' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('note.body', 'Administrator correction');

    expect(CaseAnalysisNote::query()->findOrFail($noteId)->body)->toBe('Administrator correction');
});

test('case update history includes only the five newest actual changes for the imported case', function () {
    $staff = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $caseFile = createCaseAnalysisCase('CSE-ACTIVITY-01');
    $otherCase = createCaseAnalysisCase('CSE-ACTIVITY-02');

    foreach (range(1, 6) as $number) {
        ActivityLog::query()->create([
            'category' => 'case-management',
            'action' => 'case.updated',
            'actor_type' => 'staff',
            'actor_id' => (string) $staff->getKey(),
            'actor_name' => $staff->name,
            'summary' => 'Case update '.$number,
            'details' => ['case_id' => $caseFile->getKey()],
            'occurred_at' => now()->addSeconds($number),
        ]);
    }
    ActivityLog::query()->create([
        'category' => 'case-management',
        'action' => 'case.updated',
        'actor_type' => 'staff',
        'actor_id' => (string) $staff->getKey(),
        'actor_name' => $staff->name,
        'summary' => 'Unrelated case update',
        'details' => ['case_id' => $otherCase->getKey()],
        'occurred_at' => now()->addMinute(),
    ]);
    ActivityLog::query()->create([
        'category' => 'case-management',
        'action' => 'case.viewed',
        'actor_type' => 'staff',
        'actor_id' => (string) $staff->getKey(),
        'actor_name' => $staff->name,
        'summary' => 'Ordinary page view',
        'details' => ['case_id' => $caseFile->getKey()],
        'occurred_at' => now()->addMinute(),
    ]);

    $this->actingAs($staff, 'web')
        ->getJson(route('cfm.case-analysis.secondary', $caseFile))
        ->assertOk()
        ->assertJsonCount(5, 'updates')
        ->assertJsonPath('updates.0.title', 'Case update 6')
        ->assertJsonMissing(['title' => 'Unrelated case update'])
        ->assertJsonMissing(['title' => 'Ordinary page view']);
});

test('case summary cache uses a case-specific version for invalidation', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    $caseFile = createCaseAnalysisCase('CSE-CACHE-ANALYSIS', 'Original title');
    $cache = app(CaseAnalysisCache::class);
    $resolveCount = 0;
    $resolver = function () use ($caseFile, &$resolveCount): array {
        $resolveCount++;

        return ['title' => $caseFile->fresh()->title];
    };

    expect($cache->rememberSummary($caseFile->getKey(), $resolver))->toBe(['title' => 'Original title'])
        ->and($cache->rememberSummary($caseFile->getKey(), $resolver))->toBe(['title' => 'Original title'])
        ->and($resolveCount)->toBe(1);

    $caseFile->update(['title' => 'Revised title']);
    app(VersionedCache::class)->bump('case-analysis-'.$caseFile->getKey());

    expect($cache->rememberSummary($caseFile->getKey(), $resolver))->toBe(['title' => 'Revised title'])
        ->and($resolveCount)->toBe(2);
});

test('case-management activity notifies the authorized case channel and invalidates its cache', function () {
    Event::fake([CaseAnalysisUpdated::class]);
    $caseFile = createCaseAnalysisCase('CSE-REALTIME-01');

    app(\App\Services\ActivityLogger::class)->record(
        'case-management',
        'case.updated',
        'Case file was updated.',
        ['case_id' => $caseFile->getKey()],
        User::factory()->create(['department' => 'cfm', 'account_status' => 'active']),
    );

    Event::assertDispatched(CaseAnalysisUpdated::class, fn (CaseAnalysisUpdated $event): bool => $event->caseId === $caseFile->getKey());
});
