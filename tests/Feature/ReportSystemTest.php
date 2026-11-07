<?php

use App\Events\AgencyReportUpdated;
use App\Jobs\BuildReportVideoThumbnailJob;
use App\Livewire\Admin\ReportManagement;
use App\Livewire\CaseDossier;
use App\Livewire\ReportWorkspace;
use App\Models\Admin;
use App\Models\Department;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\ReportMedia;
use App\Models\User;
use App\Services\PersonMediaAccess;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    foreach (['cfm', 'pm', 'fm', 'sm'] as $department) {
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
            ->assertSee('data-vault-image-slow-zoom', false)
            ->assertSee('data-vault-image-rotate', false)
            ->assertSee('data-vault-image-dimensions', false)
            ->assertSee('data-vault-image-extension', false)
            ->assertSee(route($department.'.reports.index'), false);
    }

    $this->actingAs(createReportSystemAdmin(), 'admin')
        ->get(route('admin.reports.index'))
        ->assertOk()
        ->assertSee('Cross-agency incident update.')
        ->assertSee('data-vault-image-viewer', false)
        ->assertSee('data-vault-image-slow-zoom', false)
        ->assertSee('data-vault-image-rotate', false)
        ->assertSee('data-vault-image-dimensions', false)
        ->assertSee('data-vault-image-extension', false)
        ->assertSee('report-feed-item__spinner', false)
        ->assertSee(route('admin.reports.manage'), false);
});

test('report editor has create and edit routes and the feed shows plain text beside first media', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter, '<p>Feed <strong>bold</strong> observation</p>');
    $path = "reports/{$report->id}/feed-video.mp4";
    Storage::disk(config('vault.upload_disk', 'vault'))->put($path, 'video');
    $thumbnailPath = 'renditions/report-video-posters/feed-video.jpg';
    Storage::disk(config('vault.upload_disk', 'vault'))->put($thumbnailPath, 'poster');
    $media = $report->media()->create([
        'path' => $path,
        'thumbnail_path' => $thumbnailPath,
        'original_name' => 'feed-video.mp4',
        'mime_type' => 'video/mp4',
        'media_type' => 'video',
        'size' => 5,
        'sort_order' => 0,
    ]);

    $this->actingAs($reporter, 'web')
        ->get(route('cfm.reports.create'))
        ->assertOk()
        ->assertSee('report-editor-page', false)
        ->assertSee('data-report-editor', false)
        ->assertDontSee('SITUATION FEED')
        ->assertSee(route('cfm.reports.index'), false);

    $this->get(route('cfm.reports.edit', ['uuid' => $report->uuid]))
        ->assertOk()
        ->assertSee('Feed <strong>bold</strong> observation', false)
        ->assertSee('SAVE REPORT');

    $this->get(route('cfm.reports.index'))
        ->assertOk()
        ->assertSee('<strong>Feed bold observation</strong>', false)
        ->assertSee('report-feed-item__thumbnail', false)
        ->assertSee(route('reports.media.thumbnail', ['report' => $report, 'media' => $media]), false)
        ->assertSee('<i class="ri ri-play-fill"></i>', false)
        ->assertDontSee('data-report-lazy-thumbnail', false)
        ->assertDontSee('class="report-feed-item__summary"><strong>Feed <strong>bold</strong>', false);

    $otherStaff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $this->actingAs($otherStaff, 'web')
        ->get(route('cfm.reports.edit', ['uuid' => $report->uuid]))
        ->assertForbidden();
});

test('report video posters are stored privately and served through authorized thumbnails', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);
    $sourcePath = "reports/{$report->uuid}/report-video.mp4";
    Storage::disk(config('vault.upload_disk', 'vault'))->put($sourcePath, 'video');
    $media = $report->media()->create([
        'path' => $sourcePath,
        'original_name' => 'report-video.mp4',
        'mime_type' => 'video/mp4',
        'media_type' => 'video',
        'size' => 5,
    ]);
    $thumbnailPath = 'renditions/report-video-posters/report-video.jpg';
    Storage::disk(config('vault.upload_disk', 'vault'))->put($thumbnailPath, 'poster');
    $media->forceFill(['thumbnail_path' => $thumbnailPath])->save();

    Queue::assertPushed(
        BuildReportVideoThumbnailJob::class,
        fn (BuildReportVideoThumbnailJob $job): bool => $job->reportMediaId === $media->id
            && $job->queue === 'video-processing',
    );

    $this->actingAs($reporter, 'web')
        ->get(route('reports.media.thumbnail', ['report' => $report, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertStreamedContent('poster');

    $this->get(route('reports.media.thumbnail', [
        'report' => createTestReport($reporter),
        'media' => $media,
    ]))->assertNotFound();
});

test('situation feed queues thumbnails for existing report videos without posters', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);
    $sourcePath = "reports/{$report->uuid}/legacy-video.mp4";
    Storage::disk(config('vault.upload_disk', 'vault'))->put($sourcePath, 'video');
    $media = $report->media()->create([
        'path' => $sourcePath,
        'original_name' => 'legacy-video.mp4',
        'mime_type' => 'video/mp4',
        'media_type' => 'video',
        'size' => 5,
    ]);

    Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->assertSee('ri-play-fill', false)
        ->assertDontSee('data-report-lazy-thumbnail', false);

    Queue::assertPushed(
        BuildReportVideoThumbnailJob::class,
        fn (BuildReportVideoThumbnailJob $job): bool => $job->reportMediaId === $media->id
            && $job->queue === 'video-processing',
    );
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
        ->assertSee('class="case-linked-report-grid"', false)
        ->assertSee('class="case-linked-report-card"', false)
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
        ->get(route('pm.people.show', ['person' => $person, 'auth' => 'unauthed']))
        ->assertOk()
        ->assertSee('Linked reports')
        ->assertSee('A person-linked operational report.')
        ->assertSee(route('pm.reports.index', ['report' => $personReport->uuid]), false);
});

test('report composer searches and lets administrators add or remove multiple case and person links', function () {
    $admin = createReportSystemAdmin();
    $firstCase = InvestigationCase::query()->create([
        'public_id' => 'CSE-SELECT-01',
        'title' => 'First selectable case',
    ]);
    $secondCase = InvestigationCase::query()->create([
        'public_id' => 'CSE-SELECT-02',
        'title' => 'Second selectable case',
    ]);
    $firstPerson = Person::query()->create([
        'public_id' => 'USR-SELECT-01',
        'name' => 'First selectable person',
    ]);
    $secondPerson = Person::query()->create([
        'public_id' => 'USR-SELECT-02',
        'name' => 'Second selectable person',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(ReportWorkspace::class)
        ->set('editorMode', true)
        ->assertSee('Type at least 2 characters to search case files.')
        ->assertSee('Type at least 2 characters to search people.')
        ->set('caseSearch', 'CSE-SELECT-01')
        ->assertSee('First selectable case')
        ->call('toggleCaseLink', $firstCase->id)
        ->assertSet('caseIds', [(string) $firstCase->id])
        ->assertSee('report-link-card', false)
        ->set('caseSearch', 'CSE-SELECT-02')
        ->assertSee('Second selectable case')
        ->call('toggleCaseLink', $secondCase->id)
        ->assertSet('caseIds', [(string) $firstCase->id, (string) $secondCase->id])
        ->set('personSearch', 'USR-SELECT-01')
        ->assertSee('First selectable person')
        ->call('togglePersonLink', $firstPerson->id)
        ->assertSet('personIds', [(string) $firstPerson->id])
        ->set('personSearch', 'USR-SELECT-02')
        ->assertSee('Second selectable person')
        ->call('togglePersonLink', $secondPerson->id)
        ->assertSet('personIds', [(string) $firstPerson->id, (string) $secondPerson->id])
        ->call('toggleCaseLink', $firstCase->id)
        ->assertSet('caseIds', [(string) $secondCase->id])
        ->call('togglePersonLink', $firstPerson->id)
        ->assertSet('personIds', [(string) $secondPerson->id]);
});

test('person and case dossiers paginate linked reports while retaining report visibility rules', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $otherStaff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-PAGINATE-01',
        'name' => 'Paginated Report Person',
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-PAGINATE-01',
        'title' => 'Paginated Report Case',
    ]);

    foreach (range(1, 11) as $index) {
        $report = createTestReport($staff, "Visible linked report {$index}.");
        $report->forceFill(['updated_at' => now()->addSeconds($index)])->saveQuietly();
        $report->people()->attach($person->id);
        $report->cases()->attach($caseFile->id);
    }
    $hiddenReport = createTestReport($otherStaff, 'Hidden linked report.');
    $hiddenReport->update(['is_hidden' => true]);
    $hiddenReport->people()->attach($person->id);
    $hiddenReport->cases()->attach($caseFile->id);

    $this->actingAs($staff, 'web')
        ->get(route('pm.people.show', ['person' => $person, 'auth' => 'unauthed']))
        ->assertOk()
        ->assertSee('Linked reports <span>11</span>', false)
        ->assertSee('Visible linked report 11.')
        ->assertDontSee('Hidden linked report.')
        ->assertSee('linkedReportsPage=2');

    $this->get(route('pm.people.show', ['person' => $person, 'auth' => 'unauthed']).'&linkedReportsPage=2')
        ->assertOk()
        ->assertSee('Visible linked report 1.')
        ->assertDontSee('Visible linked report 11.')
        ->assertDontSee('Hidden linked report.');

    $caseDossier = Livewire::actingAs($staff, 'web')
        ->test(CaseDossier::class, ['caseFile' => $caseFile])
        ->assertSee('Linked reports <span>11</span>', false)
        ->assertSee('Visible linked report 11.')
        ->assertDontSee('Visible linked report 1.')
        ->assertSee(route('cases.reports', $caseFile), false)
        ->assertDontSee('Hidden linked report.');
});

test('case relationship directories cap dossier previews and search complete linked records', function () {
    Queue::fake();
    $admin = createReportSystemAdmin();
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-CONNECTIONS-01',
        'title' => 'Relationship directory case',
    ]);

    $portraitPath = 'people/main/linked-subject.jpg';
    Storage::disk('vault')->put($portraitPath, 'portrait');
    $portraitPerson = Person::query()->create([
        'public_id' => 'USR-LINKED-PORTRAIT',
        'name' => 'Linked Subject 00',
        'main_image_path' => $portraitPath,
    ]);
    $caseFile->people()->attach($portraitPerson);
    for ($index = 1; $index <= 21; $index++) {
        $person = Person::query()->create([
            'public_id' => sprintf('USR-LINKED%02d', $index),
            'name' => sprintf('Linked Subject %02d', $index),
        ]);
        $caseFile->people()->attach($person);
    }

    $this->actingAs($admin, 'admin');
    $request = request();
    $request->setUserResolver(fn () => $admin);
    $portraitToken = app(PersonMediaAccess::class)->issue($request, $portraitPerson)['token'];
    expect(app(PersonMediaAccess::class)->activeToken($request, $portraitPerson->getKey(), $admin))
        ->toBe($portraitToken);

    $latestReport = null;
    for ($index = 1; $index <= 9; $index++) {
        $report = createTestReport($admin, sprintf('Relationship evidence report %02d.', $index));
        $report->cases()->attach($caseFile->id);
        $report->forceFill(['updated_at' => now()->addSeconds($index)])->saveQuietly();
        if ($index === 9) {
            $latestReport = $report;
            $report->media()->create([
                'path' => 'reports/relationship-video.mp4',
                'thumbnail_path' => 'renditions/report-video-posters/relationship-video.jpg',
                'original_name' => 'relationship-video.mp4',
                'mime_type' => 'video/mp4',
                'media_type' => 'video',
                'size' => 100,
                'sort_order' => 0,
            ]);
        }
    }

    $this->get(route('cases.show', $caseFile))
        ->assertOk()
        ->assertSee('Linked people <span>22</span>', false)
        ->assertSee('Linked Subject 01')
        ->assertDontSee('Linked Subject 20')
        ->assertDontSee('Linked Subject 21')
        ->assertSee(route('cases.people', $caseFile), false)
        ->assertSee(route('people.images.thumbnail', [
            'person' => $portraitPerson,
            'image' => 'main',
            'auth' => $portraitToken,
        ]), false)
        ->assertSee('Linked reports <span>9</span>', false)
        ->assertSee('Relationship evidence report 09.')
        ->assertDontSee('Relationship evidence report 01.')
        ->assertSee(route('cases.reports', $caseFile), false)
        ->assertSee(route('reports.media.thumbnail', [$latestReport, $latestReport->media()->first()]), false);

    $this->get(route('people.images.thumbnail', [
        'person' => $portraitPerson,
        'image' => 'main',
        'auth' => $portraitToken,
    ]))->assertOk();

    $this->get(route('cases.people', ['caseFile' => $caseFile, 'q' => 'Linked Subject 21']))
        ->assertOk()
        ->assertSee('Linked Subject 21')
        ->assertDontSee('Linked Subject 20');

    $this->get(route('cases.reports', ['caseFile' => $caseFile, 'q' => 'evidence report 09']))
        ->assertOk()
        ->assertSee('Relationship evidence report 09.')
        ->assertDontSee('Relationship evidence report 08.');
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

test('disabling report comments hides discussion surfaces and blocks comment actions', function () {
    app(SettingService::class)->set('report_comments_enabled', '0');
    $owner = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($owner);
    $comment = $report->comments()->create([
        'author_type' => User::class,
        'author_id' => $owner->id,
        'body' => 'This discussion must not be visible.',
    ]);
    $reply = $report->comments()->create([
        'parent_id' => $comment->id,
        'author_type' => User::class,
        'author_id' => $owner->id,
        'body' => 'This reply must not be visible.',
    ]);

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->set('activeSection', 'comments')
        ->assertSet('activeSection', 'report')
        ->assertDontSee('comments-tab', false)
        ->assertDontSee('report-comments-panel', false)
        ->assertDontSee('report-latest-comment', false)
        ->assertDontSee('This discussion must not be visible.')
        ->assertDontSee('This reply must not be visible.')
        ->call('showSection', 'comments')
        ->assertForbidden();

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->set('commentBody', 'A forbidden comment.')
        ->call('addComment')
        ->assertForbidden();

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->set('replyingToId', $comment->id)
        ->set('replyBody', 'A forbidden reply.')
        ->call('addReply')
        ->assertForbidden();

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->call('toggleReplies', $comment->id)
        ->assertForbidden();

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->call('loadMoreComments')
        ->assertForbidden();

    expect($report->comments()->count())->toBe(2)
        ->and($reply->exists)->toBeTrue();
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
    Queue::fake();
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
        ->set('editorMode', true)
        ->assertSee('data-report-previews wire:ignore', false);
});

test('report media previews include videos in detail and gallery without exposing media names', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter, 'Report with private media.');

    foreach ([
        ['evidence-video-secret.mp4', 'video/mp4', 'video'],
        ['evidence-image-one-secret.jpg', 'image/jpeg', 'image'],
        ['evidence-image-two-secret.jpg', 'image/jpeg', 'image'],
        ['evidence-image-three-secret.jpg', 'image/jpeg', 'image'],
        ['evidence-image-four-secret.jpg', 'image/jpeg', 'image'],
    ] as $index => [$name, $mimeType, $mediaType]) {
        $path = "reports/{$report->id}/{$name}";
        Storage::disk(config('vault.upload_disk', 'vault'))->put($path, "media-{$index}");
        $report->media()->create([
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $mimeType,
            'media_type' => $mediaType,
            'size' => 7,
            'sort_order' => $index,
        ]);
    }

    $video = $report->media()->where('media_type', 'video')->firstOrFail();
    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->assertSee('data-report-video-thumbnail', false)
        ->assertSee(route('reports.media', ['report' => $report, 'media' => $video]), false)
        ->assertSee('wire:key="report-detail-video-'.$report->id.'-'.$video->id.'"', false)
        ->assertSee('data-report-gallery-open="true"', false)
        ->assertSee('data-report-media-item', false)
        ->assertDontSee('evidence-video-secret.mp4')
        ->assertDontSee('evidence-image-one-secret.jpg')
        ->assertDontSee('evidence-image-two-secret.jpg')
        ->assertDontSee('evidence-image-three-secret.jpg')
        ->assertDontSee('evidence-image-four-secret.jpg');

    $this->get(route('cfm.reports.edit', ['uuid' => $report->uuid]))
        ->assertOk()
        ->assertSee('data-report-video-thumbnail', false)
        ->assertSee('wire:key="report-edit-video-'.$report->id.'-'.$video->id.'"', false)
        ->assertDontSee('evidence-video-secret.mp4')
        ->assertDontSee('evidence-image-one-secret.jpg')
        ->assertDontSee('evidence-image-two-secret.jpg')
        ->assertDontSee('evidence-image-three-secret.jpg')
        ->assertDontSee('evidence-image-four-secret.jpg');
});

test('switching reports replaces video thumbnails with the selected report media', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $firstReport = createTestReport($reporter, 'First report with a video.');
    $secondReport = createTestReport($reporter, 'Second report with a video.');

    $videos = [];
    foreach ([$firstReport, $secondReport] as $index => $report) {
        $path = "reports/{$report->id}/report-video-{$index}.mp4";
        Storage::disk(config('vault.upload_disk', 'vault'))->put($path, "video-{$index}");
        $videos[] = $report->media()->create([
            'path' => $path,
            'original_name' => "report-video-{$index}.mp4",
            'mime_type' => 'video/mp4',
            'media_type' => 'video',
            'size' => 8,
            'sort_order' => 0,
        ]);
    }

    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->call('selectReport', $firstReport->uuid, $firstReport->id)
        ->assertSee('wire:key="report-detail-video-'.$firstReport->id.'-'.$videos[0]->id.'"', false)
        ->call('selectReport', $secondReport->uuid, $secondReport->id)
        ->assertSee('wire:key="report-detail-video-'.$secondReport->id.'-'.$videos[1]->id.'"', false)
        ->assertSee(route('reports.media', ['report' => $secondReport, 'media' => $videos[1]]), false)
        ->assertDontSee(route('reports.media', ['report' => $firstReport, 'media' => $videos[0]]), false);

    $workspace->set('editorMode', true)
        ->assertSee('data-report-editor contenteditable="true"', false)
        ->assertSee('PREPARING MEDIA…')
        ->assertSee('PUBLISHING…');
});

test('report editor provides rich formatting tools and a separate safe preview overlay', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $body = '<h2>Situation title</h2><h3>Important caption</h3><p>Formatted <strong>bold</strong>, <b>browser bold</b>, <em>italic</em>, <u>underlined</u> and _#literal marker_#.</p><table><tbody><tr><td>Cell one</td><td>Cell two</td><td>Cell three</td><td>Cell four</td></tr></tbody></table><script>alert(1)</script>';
    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->set('editorMode', true)
        ->assertSee('data-report-format-command="bold"', false)
        ->assertSee('data-report-format-command="italic"', false)
        ->assertSee('data-report-format-command="underline"', false)
        ->assertSee('data-report-format-command="insertUnorderedList"', false)
        ->assertSee('data-report-font-size', false)
        ->assertSee('data-report-table-columns', false)
        ->assertSee('data-report-table-insert', false)
        ->assertSee('data-report-table-add-row', false)
        ->assertSee('data-report-table-remove-row', false)
        ->assertSee('Section title')
        ->assertSee('Caption')
        ->assertSee('data-report-editor contenteditable="true"', false)
        ->assertSee('data-report-preview-content', false)
        ->assertSee('data-report-preview-backdrop hidden', false)
        ->assertDontSee('Formatting:', false)
        ->assertDontSee('report-format-preview', false)
        ->assertDontSee('x-on:click.self', false)
        ->set('body', $body)
        ->call('saveReport')
        ->assertDispatched('report-editor-saved', draftKey: 'report-editor-v1-'.hash(
            'sha256',
            User::class.':'.$reporter->getAuthIdentifier().':new',
        ))
        ->assertSet('body', '')
        ->assertHasNoErrors();
    $report = Report::query()->where('body', 'like', '%Formatted%')->firstOrFail();
    expect($report->body)
        ->toContain('<h2>Situation title</h2>')
        ->toContain('<h3>Important caption</h3>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<b>browser bold</b>')
        ->toContain('<table><tbody><tr><td>Cell one</td><td>Cell two</td><td>Cell three</td></tr></tbody></table>')
        ->not->toContain('Cell four')
        ->toContain('_#literal marker_#')
        ->not->toContain('<script>');
    $workspace->assertRedirect(route('cfm.reports.index', ['report' => $report->uuid]));

    $this->get(route('cfm.reports.edit', ['uuid' => $report->uuid]))
        ->assertOk()
        ->assertSee('<strong>bold</strong>', false)
        ->assertSee('<h2>Situation title</h2>', false)
        ->assertSee('<h3>Important caption</h3>', false)
        ->assertSee('<table><tbody><tr><td>Cell one</td><td>Cell two</td><td>Cell three</td></tr></tbody></table>', false)
        ->assertSee('_#literal marker_#')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('data-report-preview-open', false);
});

test('report editor exposes local draft controls and discard redirects without publishing', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    $this->actingAs($reporter, 'web')
        ->get(route('cfm.reports.create'))
        ->assertOk()
        ->assertSee('data-report-draft-key', false)
        ->assertSee('data-report-dropzone', false)
        ->assertSee('data-report-files', false)
        ->assertSee('data-report-draft-status', false)
        ->assertSee('data-report-discard', false);

    Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->set('editorMode', true)
        ->set('body', '<p>Unpublished draft.</p>')
        ->call('discardReport')
        ->assertRedirect(route('cfm.reports.index'));

    expect(Report::query()->count())->toBe(0);
});

test('existing report attachments can be individually marked for removal while editing', function () {
    Queue::fake();
    Storage::fake(config('vault.upload_disk', 'vault'));
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);
    $path = "reports/{$report->uuid}/existing-image.jpg";
    Storage::disk(config('vault.upload_disk', 'vault'))->put($path, 'image');
    $media = $report->media()->create([
        'path' => $path,
        'original_name' => 'existing-image.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'size' => 5,
    ]);

    Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->set('editorMode', true)
        ->set('editingUuid', $report->uuid)
        ->call('toggleMediaRemoval', $media->id)
        ->assertSet('removeMediaIds', [$media->id])
        ->call('toggleMediaRemoval', $media->id)
        ->assertSet('removeMediaIds', []);
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

    $workspace->call('selectReport', $report->uuid, $report->id)
        ->assertSee('Measured report detail request.');

    $detailQueries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'reports') && str_contains(strtolower($query), 'uuid'));
    $connection->disableQueryLog();

    expect($detailQueries)->toHaveCount(1);
});

test('report comment drafts survive targeted realtime rerenders', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);

    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid)
        ->call('showSection', 'comments')
        ->set('commentBody', 'An unsaved note that must stay in the composer.')
        ->dispatch('agency-report-comments-updated')
        ->assertSet('commentBody', 'An unsaved note that must stay in the composer.');

    $workspace->call('addComment')
        ->assertHasNoErrors()
        ->assertSet('commentBody', '');

    expect($report->comments()->where('body', 'An unsaved note that must stay in the composer.')->exists())->toBeTrue();
});

test('report comments are queried only while the comments section is active', function () {
    $reporter = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $report = createTestReport($reporter);
    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->set('selectedReport', $report->uuid);
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $workspace->dispatch('agency-report-list-updated');
    $reportTabQueries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'report_comments'));

    $connection->flushQueryLog();
    $workspace->call('showSection', 'comments');
    $commentsTabQueries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'report_comments'));
    $connection->disableQueryLog();

    expect($reportTabQueries)->toBeEmpty()
        ->and($commentsTabQueries)->not->toBeEmpty();
});

test('report-specific realtime channels preserve report visibility restrictions', function () {
    $owner = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $otherStaff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $visibleReport = createTestReport($owner);
    $hiddenReport = createTestReport($owner);
    $hiddenReport->update(['is_hidden' => true]);
    $channels = Broadcast::connection()->getChannels();
    $authorizeReportChannel = $channels['reports.{reportId}'];
    $authorizeControlChannel = $channels['reports.control'];

    expect($authorizeReportChannel($owner, $hiddenReport->id))->toBeTrue()
        ->and($authorizeReportChannel($otherStaff, $visibleReport->id))->toBeTrue()
        ->and($authorizeReportChannel($otherStaff, $hiddenReport->id))->toBeFalse()
        ->and($authorizeReportChannel($otherStaff, $visibleReport->id + 1000))->toBeFalse()
        ->and($authorizeControlChannel($otherStaff))->toBeFalse()
        ->and($authorizeControlChannel(createReportSystemAdmin()))->toBeTrue();
});
