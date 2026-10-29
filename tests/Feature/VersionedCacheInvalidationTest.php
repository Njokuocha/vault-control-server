<?php

use App\Livewire\ReportWorkspace;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(DatabaseMigrations::class);

test('model cache invalidation waits for commit and is discarded on rollback', function () {
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-CACHE-01',
        'title' => 'Cacheable original case',
    ]);
    $key = 'vault:cache-version:cases';
    $version = (int) Cache::get($key, 0);

    DB::beginTransaction();
    $caseFile->update(['title' => 'Uncommitted case change']);
    expect((int) Cache::get($key, 0))->toBe($version);
    DB::rollBack();

    expect((int) Cache::get($key, 0))->toBe($version);

    DB::transaction(fn () => $caseFile->update(['title' => 'Committed case change']));

    expect((int) Cache::get($key, 0))->toBeGreaterThan($version);
});

test('case edits invalidate report link suggestions after commit', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-CACHE-02',
        'title' => 'Cacheable original case',
    ]);

    $workspace = Livewire::actingAs($staff, 'web')
        ->test(ReportWorkspace::class)
        ->call('openComposer')
        ->set('caseSearch', 'Cacheable')
        ->assertSee('Cacheable original case');

    DB::transaction(fn () => $caseFile->update(['title' => 'Cacheable revised case']));

    $workspace->call('refreshReports')
        ->assertDontSee('Cacheable original case')
        ->assertSee('Cacheable revised case');
});

test('linked person edits invalidate cached report details after commit', function () {
    $reporter = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-CACHE-01',
        'name' => 'Original linked person',
    ]);
    $report = Report::query()->create([
        'uuid' => (string) Str::uuid(),
        'body' => 'A report linked to a person.',
        'reporter_type' => $reporter::class,
        'reporter_id' => $reporter->id,
    ]);
    $report->people()->attach($person);
    $workspace = Livewire::actingAs($reporter, 'web')
        ->test(ReportWorkspace::class)
        ->assertSee('USR-CACHE-01');
    $reportVersion = (int) Cache::get('vault:cache-version:reports', 0);

    DB::transaction(fn () => $person->update([
        'public_id' => 'USR-CACHE-02',
        'name' => 'Updated linked person',
    ]));

    expect((int) Cache::get('vault:cache-version:reports', 0))->toBeGreaterThan($reportVersion);

    $workspace->call('refreshReports')
        ->assertDontSee('USR-CACHE-01')
        ->assertSee('USR-CACHE-02');
});
