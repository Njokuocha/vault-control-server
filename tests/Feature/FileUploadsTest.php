<?php

use App\Models\File as VaultFile;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'vault.max_upload_mb' => 10,
        'vault.api_upload_enabled' => true,
        'vault.upload_disk' => 'vault',
    ]);
    Storage::fake('vault');
    app(SettingService::class)->set('upload_key', 'test-upload-key');
});

test('the API stores an uploaded file privately and returns its metadata', function () {
    $response = $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('private note.txt', 2, 'text/plain'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.0.name', 'private note.txt')
        ->assertJsonPath('data.0.mime', 'text/plain');

    $file = VaultFile::query()->firstOrFail();
    expect(Storage::disk('vault')->exists($file->stored_path))->toBeTrue()
        ->and($file->source_ip)->not->toBeNull()
        ->and($file->checksum)->toMatch('/^[a-f0-9]{64}$/');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('private note.txt')
        ->assertSee('TOTAL FILES');
});

test('the API rejects an invalid key and a disabled API upload setting', function () {
    $this->post(route('api.upload', ['auth' => 'incorrect']), [
        'file' => UploadedFile::fake()->create('private.txt', 1, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertUnauthorized();

    app(SettingService::class)->set('api_upload_enabled', '0');
    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('private.txt', 1, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(VaultFile::query()->count())->toBe(0);
});

test('a zero-megabyte limit blocks manual and API uploads', function () {
    config(['vault.max_upload_mb' => 0]);

    $this->actingAs(User::factory()->create())
        ->get(route('uploads.create'))
        ->assertOk()
        ->assertSee('Uploads are disabled');

    $this->post(route('uploads.store'), [
        'files' => [UploadedFile::fake()->create('private.txt', 1, 'text/plain')],
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('private.txt', 1, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(VaultFile::query()->count())->toBe(0);
});

test('manual uploads store files on the private disk for authenticated administrators', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('uploads.store'), [
            'files' => [UploadedFile::fake()->create('manual record.txt', 1, 'text/plain')],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', '1 file uploaded successfully.');

    $file = VaultFile::query()->firstOrFail();
    expect($file->original_name)->toBe('manual record.txt')
        ->and(Storage::disk('vault')->exists($file->stored_path))->toBeTrue();

    $this->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('manual record.txt')
        ->assertSee('TOTAL FILES')
        ->assertSee('INDEXED RECORDS')
        ->assertSee('data-archive-preview', false);
});

test('uploads exceeding the configured per-file limit are rejected', function () {
    config(['vault.max_upload_mb' => 1]);

    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('large.txt', 1025, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect(VaultFile::query()->count())->toBe(0);
});
