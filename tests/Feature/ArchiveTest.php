<?php

use App\Models\File as VaultFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the archive overview shows only the latest twenty records', function () {
    $user = User::factory()->create();

    foreach (range(0, 24) as $index) {
        $file = new VaultFile([
            'uuid' => (string) Str::uuid(),
            'original_name' => 'record-'.$index.'.txt',
            'stored_path' => 'archive/record-'.$index.'.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'category' => 'text',
            'size' => 10,
            'checksum' => hash('sha256', (string) $index),
        ]);
        $file->created_at = Carbon::parse('2026-10-01')->addMinutes($index);
        $file->save();
    }

    $this->actingAs($user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('record-24.txt')
        ->assertDontSee('record-0.txt')
        ->assertSee('VIEW FULL ARCHIVE')
        ->assertSee('LATEST OF');
});

test('the full archive endpoint supports combined text, year, and month filters', function () {
    $user = User::factory()->create();

    foreach ([
        ['Quarterly Report 2026.txt', 'text', '2026-10-15'],
        ['Quarterly Report 2025.pdf', 'pdf', '2025-10-15'],
        ['Quarterly Report November.txt', 'text', '2026-11-15'],
    ] as $index => [$name, $category, $date]) {
        $file = new VaultFile([
            'uuid' => (string) Str::uuid(),
            'original_name' => $name,
            'stored_path' => 'archive/'.$index.'.file',
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'mime_type' => $category === 'pdf' ? 'application/pdf' : 'text/plain',
            'category' => $category,
            'size' => 10,
            'checksum' => hash('sha256', (string) $index),
        ]);
        $file->created_at = Carbon::parse($date);
        $file->save();
    }

    $this->actingAs($user)
        ->getJson(route('archive.files', ['q' => 'quarterly', 'year' => '2026', 'month' => 10]))
        ->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.name', 'Quarterly Report 2026.txt')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('years.0', '2026');

    $this->getJson(route('archive.files', ['month' => 10]))
        ->assertOk()
        ->assertJsonCount(2, 'records');

    $this->getJson(route('archive.files', ['extension' => 'PDF']))
        ->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.name', 'Quarterly Report 2025.pdf')
        ->assertJsonPath('extensions.0', 'pdf');

    expect($this->getJson(route('archive.files'))->json('records.0'))
        ->toHaveKey('uuid')
        ->not->toHaveKey('path');
});

test('the full archive filters by upload method', function () {
    foreach (['api', 'manual'] as $method) {
        VaultFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'original_name' => $method.'-record.txt',
            'stored_path' => $method.'.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'category' => 'text',
            'upload_method' => $method,
            'size' => 10,
            'checksum' => hash('sha256', $method),
        ]);
    }

    $this->actingAs(User::factory()->create())
        ->getJson(route('archive.files', ['upload_method' => 'api']))
        ->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.upload_method', 'api');
});

test('the full archive endpoint rejects unsupported extension filter values', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('archive.files', ['extension' => '../txt']))
        ->assertUnprocessable();
});

test('the full archive endpoint requires an authenticated session', function () {
    $this->getJson(route('archive.files'))->assertUnauthorized();
});

test('archive metric cache is invalidated when a file record is saved', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertSeeInOrder(['TOTAL FILES', '0']);

    VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'new-record.txt',
        'stored_path' => 'archive/new-record.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'category' => 'text',
        'size' => 10,
        'checksum' => hash('sha256', 'new-record'),
    ]);

    $this->get(route('dashboard.index'))
        ->assertSeeInOrder(['TOTAL FILES', '1']);

    $file = VaultFile::query()->firstOrFail();
    $file->update(['original_name' => 'renamed-record.txt', 'size' => 20]);
    $this->get(route('dashboard.index'))
        ->assertSee('renamed-record.txt')
        ->assertSee('20 B');

    $file->delete();
    $this->get(route('dashboard.index'))
        ->assertSeeInOrder(['TOTAL FILES', '0'])
        ->assertDontSee('renamed-record.txt');
});

test('cached archive search results invalidate immediately after file changes', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('archive.files', ['q' => 'refresh-token']))
        ->assertOk()
        ->assertJsonCount(0, 'records');

    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'refresh-token.txt',
        'stored_path' => 'archive/refresh-token.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'category' => 'text',
        'size' => 10,
        'checksum' => hash('sha256', 'before'),
    ]);
    $this->getJson(route('archive.files', ['q' => 'refresh-token']))
        ->assertJsonPath('records.0.name', 'refresh-token.txt');

    $file->update(['original_name' => 'refresh-token-renamed.txt']);
    $this->getJson(route('archive.files', ['q' => 'refresh-token']))
        ->assertJsonPath('records.0.name', 'refresh-token-renamed.txt');

    $file->delete();
    $this->getJson(route('archive.files', ['q' => 'refresh-token']))
        ->assertJsonCount(0, 'records');
});

test('the full dashboard archive searches through Livewire and privately previews the selected file', function () {
    Storage::fake('vault');
    $user = User::factory()->create();
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'livewire-archive-target.txt',
        'stored_path' => 'archive/livewire-archive-target.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'category' => 'text',
        'size' => 17,
        'checksum' => hash('sha256', 'private preview'),
    ]);
    Storage::disk('vault')->put($file->stored_path, 'private preview');

    $this->actingAs($user);

    Livewire::test(\App\Livewire\DashboardArchive::class)
        ->assertDontSee($file->original_name)
        ->call('open')
        ->assertSee($file->original_name)
        ->set('search', 'livewire-archive-target')
        ->assertSee($file->original_name)
        ->set('search', 'not-a-match')
        ->assertDontSee($file->original_name)
        ->set('search', 'livewire-archive-target')
        ->call('preview', $file->uuid)
        ->assertDispatched('archive-preview-ready');
});

test('dashboard archive search and category filters update through Livewire events', function () {
    $this->actingAs(User::factory()->create());

    VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'dashboard-document.txt',
        'stored_path' => 'archive/dashboard-document.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'category' => 'text',
        'size' => 10,
        'checksum' => hash('sha256', 'dashboard-document'),
    ]);
    VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'dashboard-image.png',
        'stored_path' => 'archive/dashboard-image.png',
        'extension' => 'png',
        'mime_type' => 'image/png',
        'category' => 'image',
        'size' => 10,
        'checksum' => hash('sha256', 'dashboard-image'),
    ]);

    Livewire::test(\App\Livewire\DashboardLatestFiles::class)
        ->assertSee('dashboard-document.txt')
        ->assertSee('dashboard-image.png')
        ->dispatch('dashboard-archive-search', search: 'document')
        ->assertSee('dashboard-document.txt')
        ->assertDontSee('dashboard-image.png')
        ->dispatch('dashboard-archive-search', search: '')
        ->dispatch('dashboard-archive-filter', filter: 'media')
        ->assertSee('dashboard-image.png')
        ->assertDontSee('dashboard-document.txt');
});
