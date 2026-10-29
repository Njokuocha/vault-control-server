<?php

use App\Livewire\ManualUploadForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('authenticated upload page renders the Livewire chunk uploader and persisted tracker', function () {
    config(['vault.max_upload_mb' => 10]);

    $this->actingAs(User::factory()->create())
        ->get(route('uploads.create'))
        ->assertOk()
        ->assertSeeLivewire(ManualUploadForm::class)
        ->assertSee('data-upload-tracker', false)
        ->assertSee('data-snapshot-url=', false)
        ->assertSee('data-manual-upload', false)
        ->assertSee('wire:ignore', false)
        ->assertSee('Select any file type.');
});

test('the upload tracker control is available across authenticated routes', function () {
    $user = User::factory()->create();

    foreach ([route('dashboard.index'), route('calculator.index'), route('uploads.create')] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertSee('data-upload-tracker-toggle', false)
            ->assertSee('id="upload-tracker-panel"', false)
            ->assertSee('aria-controls="upload-tracker-panel"', false);
    }
});

test('the file management upload page uses its own route with the shared transfer tracker', function () {
    $staff = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);

    $this->actingAs($staff, 'web')
        ->get(route('fm.uploads.create'))
        ->assertOk()
        ->assertSeeLivewire(ManualUploadForm::class)
        ->assertSee('FILE MANAGEMENT INGESTION')
        ->assertSee('action="'.route('fm.uploads.create').'"', false)
        ->assertSee('data-upload-page-url="'.route('fm.uploads.create').'"', false)
        ->assertSee('data-transfer-initialize-url', false);
});

test('the Livewire upload form updates its selected file summary', function () {
    Livewire::test(ManualUploadForm::class, [
        'maxUploadMb' => 10,
        'maxVideoUploadMb' => 0,
    ])
        ->dispatch('manual-upload-selection', count: 4)
        ->assertSee('4 files selected.');
});
