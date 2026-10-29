<?php

use App\Events\AgencyReportUpdated;
use App\Livewire\Admin\ReportManagement;
use App\Livewire\ReportWorkspace;
use App\Models\Admin;
use App\Models\Department;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\ReportMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createReportSystemAdmin(): Admin
{
    return Admin::query()->create([
        'name' => 'Report Administrator',
        'email' => 'report-admin@example.test',
        'password' => 'a-secure-password',
    ]);
}

function createTestReport(User|Admin $reporter, string $body = 'A field observation for the agency.'): Report
{
    return Report::query()->create([
        'uuid' => (string) Str::uuid(),
        'body' => $body,
        'reporter_type' => $reporter::class,
        'reporter_id' => $reporter->getKey(),
    ]);
}

test('active staff in each department and administrators can open the agency report system', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter, 'Cross-agency incident update.');

    foreach (['cfm', 'pm', 'fm'] as $department) {
        $staff = User::factory()->create([
            'department' => $department,
            'account_status' => 'active',
        ]);
        $this->actingAs($staff, 'web')
            ->get(route($department.'.reports.index'))
            ->assertOk()
            ->assertSee('Report System')
            ->assertSee('Cross-agency incident update.')
            ->assertSee('data-vault-image-viewer', false)
            ->assertSee(route($department.'.reports.index'), false);
    }

    $this->actingAs(createReportSystemAdmin(), 'admin')
        ->get(route('admin.reports.index'))
        ->assertOk()
        ->assertSee('Cross-agency incident update.')
        ->assertSee('data-vault-image-viewer', false)
        ->assertSee('report-feed-item__spinner', false)
        ->assertSee(route('admin.reports.manage'), false);
});

test('staff can publish reports with permitted case and person links and reciprocal record pages show them', function () {
    Event::fake([AgencyReportUpdated::class]);
    $caseManager = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-REPORT-01',
        'title' => 'Cross-border incident',
        'classification' => 'CLASSIFIED',
        'case_status' => 'OPEN',
        'narrative' => 'Case context.',
        'comment' => 'Continuity note.',
    ]);

    Livewire::actingAs($caseManager, 'web')
        ->test(ReportWorkspace::class)
        ->set('body', 'A witnessed event with immediate operational relevance.')
        ->set('caseIds', [(string) $caseFile->id])
        ->call('saveReport')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success');

    $report = Report::query()->where('body', 'A witnessed event with immediate operational relevance.')->firstOrFail();
    expect($report->cases()->whereKey($caseFile->id)->exists())->toBeTrue();
    Event::assertDispatched(AgencyReportUpdated::class, fn ($event): bool => $event->reportId === $report->id && $event->action === 'created');

    $this->actingAs($caseManager, 'web')
        ->get(route('cfm.cases.show', $caseFile))
        ->assertOk()
        ->assertSee('Linked reports')
        ->assertSee('A witnessed event with immediate operational relevance.')
        ->assertSee(route('cfm.reports.index', ['report' => $report->uuid]), false);

    $profileManager = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-REPORT-01',
        'name' => 'Report Linked Person',
    ]);
    Livewire::actingAs($profileManager, 'web')
        ->test(ReportWorkspace::class)
        ->set('body', 'A person-linked operational report.')
        ->set('personIds', [(string) $person->id])
        ->call('saveReport')
        ->assertHasNoErrors();

    $personReport = Report::query()->where('body', 'A person-linked operational report.')->firstOrFail();
    expect($personReport->people()->whereKey($person->id)->exists())->toBeTrue();
    $this->actingAs($profileManager, 'web')
        ->get(route('pm.people.show', $person))
        ->assertOk()
        ->assertSee('Linked reports')
        ->assertSee('A person-linked operational report.')
        ->assertSee(route('pm.reports.index', ['report' => $personReport->uuid]), false);
});

test('staff can comment but only the report owner or an administrator can reply', function () {
    Event::fake([AgencyReportUpdated::class]);
    $owner = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $commenter = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($owner);

    $commenterWorkspace = Livewire::actingAs($commenter, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->set('commentBody', 'Additional corroborating detail.')
        ->call('addComment')
        ->assertHasNoErrors();
    $comment = $report->comments()->where('body', 'Additional corroborating detail.')->firstOrFail();

    $commenterWorkspace
        ->set('replyingToId', $comment->id)
        ->set('replyBody', 'An unauthorized reply.')
        ->call('addReply')
        ->assertForbidden();

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->set('replyingToId', $comment->id)
        ->set('replyBody', 'Reporter clarification.')
        ->call('addReply')
        ->assertHasNoErrors();

    $adminComment = ReportComment::query()->create([
        'report_id' => $report->id,
        'author_type' => Admin::class,
        'author_id' => createReportSystemAdmin()->id,
        'body' => 'Administrator priority note.',
    ]);
    $newestStaffComment = $report->comments()->create([
        'author_type' => User::class,
        'author_id' => $commenter->id,
        'body' => 'Newer field observation.',
    ]);

    Livewire::actingAs($commenter, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->call('showSection', 'comments')
        ->assertSeeInOrder(['Administrator priority note.', 'Newer field observation.'])
        ->assertSee('VIEW REPLIES');

    expect($adminComment->id)->not->toBe($newestStaffComment->id);
});

test('hidden reports are restricted to administrators and their reporter', function () {
    $owner = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);
    $otherStaff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($owner, 'Restricted operational detail.');
    $report->update(['is_hidden' => true]);

    Livewire::actingAs($otherStaff, 'web')
        ->test(ReportWorkspace::class)
        ->assertDontSee('Restricted operational detail.');

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->assertSee('Restricted operational detail.');

    Livewire::actingAs(createReportSystemAdmin(), 'admin')
        ->test(ReportWorkspace::class)
        ->assertSee('Restricted operational detail.');
});

test('administrators may delete reports with comments and media but record links block deletion', function () {
    Event::fake([AgencyReportUpdated::class]);
    Storage::fake(config('vault.upload_disk', 'vault'));
    $admin = createReportSystemAdmin();
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $connectedReport = createTestReport($reporter, 'Linked report cannot be deleted.');
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-REPORT-02',
        'title' => 'Connected case',
    ]);
    $connectedReport->cases()->attach($caseFile);

    Livewire::actingAs($admin, 'admin')
        ->test(ReportManagement::class)
        ->call('requestDelete', $connectedReport->id)
        ->assertSee('Report is linked')
        ->call('deleteReport')
        ->assertDispatched('alert', type: 'error');
    expect(Report::query()->whereKey($connectedReport->id)->exists())->toBeTrue();

    $report = createTestReport($reporter, 'Unlinked report can be deleted.');
    $report->comments()->create([
        'author_type' => User::class,
        'author_id' => $reporter->id,
        'body' => 'Retained with the report until delete.',
    ]);
    $path = 'reports/'.$report->id.'/attachment.jpg';
    Storage::disk(config('vault.upload_disk', 'vault'))->put($path, 'test media');
    $media = $report->media()->create([
        'path' => $path,
        'original_name' => 'attachment.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'size' => 10,
        'sort_order' => 1,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(ReportManagement::class)
        ->call('requestDelete', $report->id)
        ->assertSee('Delete this report?')
        ->call('deleteReport')
        ->assertDispatched('alert', type: 'success');

    expect(Report::query()->whereKey($report->id)->exists())->toBeFalse()
        ->and(ReportMedia::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and(Storage::disk(config('vault.upload_disk', 'vault'))->exists($path))->toBeFalse();
});

test('report control shows the reporters configured departmental rank', function () {
    $department = Department::query()->where('code', 'cfm')->firstOrFail();
    $department->update(['rank' => 'Senior Case Officer']);
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    createTestReport($reporter);

    Livewire::actingAs(createReportSystemAdmin(), 'admin')
        ->test(ReportManagement::class)
        ->assertSee('SENIOR CASE OFFICER')
        ->assertDontSee('AGENCY STAFF');
});

test('report attachment preview host is ignored by Livewire DOM morphing', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    Livewire::actingAs($staff, 'web')
        ->test(ReportWorkspace::class)
        ->call('openComposer')
        ->assertSee('data-report-previews wire:ignore', false);
});

test('report media remains private and supports byte-range playback', function () {
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);
    $path = "reports/{$report->id}/evidence.png";
    Storage::disk(config('vault.upload_disk', 'vault'))->put($path, '0123456789');
    $media = $report->media()->create([
        'path' => $path,
        'original_name' => 'evidence.png',
        'mime_type' => 'image/png',
        'media_type' => 'image',
        'size' => 10,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($reporter, 'web')
        ->get(route('reports.media', ['report' => $report, 'media' => $media]), ['Range' => 'bytes=2-5']);

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 2-5/10');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    ob_start();
    $response->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('2345');
});

test('selecting a report reuses the loaded detail during the Livewire render', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $workspace = Livewire::actingAs($reporter, 'web')->test(ReportWorkspace::class);
    $report = createTestReport($reporter, 'Measured report detail request.');
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $workspace->call('selectReport', $report->uuid)
        ->assertSee('Measured report detail request.');

    $detailQueries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'reports') && str_contains(strtolower($query), 'uuid'));
    $connection->disableQueryLog();

    expect($detailQueries)->toHaveCount(1);
});
