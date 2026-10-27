<?php

use App\Models\File as VaultFile;
use App\Models\User;
use App\Events\FileDeleted;
use App\Events\FileEdited;
use App\Events\FileUploaded;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;

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
        'file' => UploadedFile::fake()->createWithContent('private note.txt', "A private note.\n"),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.0.name', 'private note.txt')
        ->assertJsonPath('data.0.mime', 'text/plain')
        ->assertJsonPath('data.0.upload_method', 'api');

    $file = VaultFile::query()->firstOrFail();
    expect(Storage::disk('vault')->exists($file->stored_path))->toBeTrue()
        ->and($file->upload_method)->toBe('api')
        ->and($file->source_ip)->not->toBeNull()
        ->and($file->checksum)->toMatch('/^[a-f0-9]{64}$/');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('private note.txt')
        ->assertSee('TOTAL FILES');
});

test('file upload broadcasts only the approved minimal metadata', function () {
    Event::fake([FileUploaded::class]);

    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('arrival.txt', 1, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertCreated();

    Event::assertDispatched(FileUploaded::class, function (FileUploaded $event): bool {
        expect($event->broadcastWith())->toHaveKeys(['uuid', 'name', 'category', 'month_key', 'status'])
            ->not->toHaveKey('stored_path')
            ->not->toHaveKey('source_ip');

        return $event->name === 'arrival.txt'
            && $event->broadcastQueue() === 'broadcasts'
            && $event->broadcastOn()[0]->name === 'private-admin.vault';
    });
});

test('file edit and delete broadcasts expose only minimal archive metadata', function () {
    $edited = new FileEdited('uuid-1', 'record.txt', 'text', '2026-10');
    $deleted = new FileDeleted('uuid-2', 'removed.txt', 'text', '2026-10');

    expect($edited->broadcastWith())->toBe([
        'uuid' => 'uuid-1',
        'name' => 'record.txt',
        'category' => 'text',
        'month_key' => '2026-10',
        'status' => 'edited',
    ])
        ->and($deleted->broadcastWith())->toBe([
            'uuid' => 'uuid-2',
            'name' => 'removed.txt',
            'category' => 'text',
            'month_key' => '2026-10',
            'status' => 'deleted',
        ])
        ->and($edited->broadcastQueue())->toBe('broadcasts')
        ->and($deleted->broadcastOn()[0]->name)->toBe('private-admin.vault');
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

    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('private.txt', 1, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(VaultFile::query()->count())->toBe(0);
});

test('manual multipart uploads are no longer accepted outside the chunked transfer flow', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('uploads.create'), [
            'files' => [UploadedFile::fake()->create('manual record.txt', 1, 'text/plain')],
        ])
        ->assertStatus(405);

    expect(VaultFile::query()->count())->toBe(0);
});

test('the manual upload page uses the unified uploader without the legacy video section', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('uploads.create'))
        ->assertOk()
        ->assertSee('Add files to the vault')
        ->assertSee('data-upload-input', false)
        ->assertSee('data-transfer-initialize-url', false)
        ->assertDontSee('BACKGROUND VIDEO UPLOAD');
});

test('uploads exceeding the configured per-file limit are rejected', function () {
    config(['vault.max_upload_mb' => 1]);

    $this->post(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => UploadedFile::fake()->create('large.txt', 1025, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect(VaultFile::query()->count())->toBe(0);
});

test('the configured video limit is enforced using detected MIME type', function () {
    config(['vault.max_upload_mb' => 10]);
    app(SettingService::class)->set('max_video_upload_mb', '1');
    $content = pack('N', 24).'ftypmp42'.pack('N', 0).pack('N', 0).'mp42'.str_repeat("\0", 1100000);
    $upload = UploadedFile::fake()->createWithContent('oversized.mp4', $content);
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
    expect($detectedMime)->toBe('video/mp4')->and($upload->getSize())->toBeGreaterThan(1048576);

    $this->postJson(route('api.upload', ['auth' => 'test-upload-key']), [
        'file' => $upload,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Video files must be no larger than 1 MB.');

    expect(VaultFile::query()->count())->toBe(0);
});
