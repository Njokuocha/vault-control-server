<?php

use App\Models\File as VaultFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
});
