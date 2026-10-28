<?php

use App\Jobs\BuildArchiveIndexJob;
use App\Models\File as VaultFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['vault.upload_disk' => 'vault']);
    Storage::fake('vault');
});

function makeVaultFile(string $path, string $name, string $mime = 'text/plain', string $extension = 'txt'): VaultFile
{
    return VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => $name,
        'stored_path' => $path,
        'extension' => $extension,
        'mime_type' => $mime,
        'category' => $extension === 'zip' ? 'archive' : 'text',
        'size' => 10,
        'checksum' => hash('sha256', Storage::disk('vault')->get($path)),
    ]);
}

function makeTestZip(): string
{
    $temporaryPath = tempnam(sys_get_temp_dir(), 'vault-zip-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to allocate a temporary ZIP path.');
    }

    $archive = new ZipArchive;
    if ($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create a test ZIP archive.');
    }
    $archive->addFromString('folder/secret.txt', 'safe entry');
    $archive->addFromString('root.txt', 'root entry');
    $archive->addFromString('../escape.txt', 'unsafe entry');
    $archive->close();

    return $temporaryPath;
}

test('guests cannot access UUID-based file endpoints', function () {
    Storage::disk('vault')->put('document.txt', 'private bytes');
    $file = makeVaultFile('document.txt', 'document.txt');

    $this->get(route('files.stream', ['file' => $file->uuid]))->assertRedirect(route('login'));
    $this->get(route('files.download', ['file' => $file->uuid]))->assertRedirect(route('login'));
});

test('private stream returns correct bytes, validators, and supports range requests', function () {
    Storage::disk('vault')->put('document.txt', '0123456789');
    $file = makeVaultFile('document.txt', 'document.txt');
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('files.stream', ['file' => $file->uuid]));
    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('must-revalidate');

    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();
    expect($content)->toBe('0123456789');

    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->get(route('files.stream', ['file' => $file->uuid]), ['If-None-Match' => $etag])
        ->assertStatus(304);

    $rangeResponse = $this->get(route('files.stream', ['file' => $file->uuid]), ['Range' => 'bytes=2-5']);
    $rangeResponse
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 2-5/10');
    ob_start();
    $rangeResponse->baseResponse->sendContent();
    $rangeContent = ob_get_clean();
    expect($rangeContent)->toBe('2345');
});

test('nginx sendfile mode delegates bytes only after Laravel authorization and keeps validators', function () {
    Storage::disk('vault')->put('2026/10/video.mp4', 'private video bytes');
    $file = makeVaultFile('2026/10/video.mp4', 'video.mp4', 'video/mp4', 'mp4');
    config(['vault.sendfile' => 'nginx']);
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('files.stream', ['file' => $file->uuid]));
    $response->assertOk()
        ->assertHeader('X-Accel-Redirect', '/_vault/2026/10/video.mp4')
        ->assertHeader('Accept-Ranges', 'bytes');
    expect($response->getContent())->toBe('');

    $this->get(route('files.stream', ['file' => $file->uuid]), [
        'If-None-Match' => $response->headers->get('ETag'),
    ])->assertStatus(304)->assertHeaderMissing('X-Accel-Redirect');
});

test('apache sendfile mode sets only the authorized private file header', function () {
    Storage::disk('vault')->put('document.txt', 'private bytes');
    $file = makeVaultFile('document.txt', 'document.txt');
    config(['vault.sendfile' => 'apache']);

    $this->actingAs(User::factory()->create())
        ->get(route('files.download', ['file' => $file->uuid]))
        ->assertOk()
        ->assertHeader('X-Sendfile', realpath(Storage::disk('vault')->path('document.txt')))
        ->assertHeader('Content-Disposition');
});

test('ZIP entries are indexed once and traversal or unknown entries are rejected', function () {
    $temporaryPath = makeTestZip();
    try {
        Storage::disk('vault')->put('archive.zip', file_get_contents($temporaryPath));
    } finally {
        unlink($temporaryPath);
    }

    $file = makeVaultFile('archive.zip', 'evidence.zip', 'application/zip', 'zip');
    (new BuildArchiveIndexJob($file->id))->handle();
    $file->refresh();

    expect($file->archive_status)->toBe('ready')
        ->and($file->archive_entry_count)->toBe(2)
        ->and($file->archive_index['children'][0]['name'])->toBe('folder');

    $this->actingAs(User::factory()->create());
    $entryUrl = route('files.archive-entry', ['file' => $file->uuid]);
    $this->getJson($entryUrl.'?path='.urlencode('../escape.txt'))->assertNotFound();
    $this->getJson($entryUrl.'?path=not-indexed.txt')->assertNotFound();
    $this->getJson($entryUrl.'?path='.urlencode('folder/secret.txt'))
        ->assertOk()
        ->assertJsonPath('preview_available', true)
        ->assertJsonPath('content', 'safe entry');
});
