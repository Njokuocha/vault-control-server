<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->shellRoot = storage_path('framework/testing/vault-shell-'.Str::uuid());
    $this->outsideFile = storage_path('framework/testing/outside-vault-shell-'.Str::uuid().'.txt');
    config(['vault.shell_root' => $this->shellRoot]);
    File::ensureDirectoryExists($this->shellRoot);
});

afterEach(function () {
    File::deleteDirectory($this->shellRoot);
    File::delete($this->outsideFile);
});

test('the private shell directory is available only to authenticated users', function () {
    $this->getJson(route('shell.directory', ['path' => '/root']))->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.directory', ['path' => '/root']))
        ->assertOk()
        ->assertJsonPath('path', '/root');
});

test('the shell lists only files and directories under its virtual root', function () {
    File::ensureDirectoryExists($this->shellRoot.DIRECTORY_SEPARATOR.'briefs');
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'briefs'.DIRECTORY_SEPARATOR.'daily note.txt', 'classified text');
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'.hidden', 'not listed');

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.directory', ['path' => '/root']))
        ->assertOk()
        ->assertJsonPath('directories.0.name', 'briefs')
        ->assertJsonCount(0, 'files');

    $this->getJson(route('shell.directory', ['path' => '/root/briefs']))
        ->assertOk()
        ->assertJsonPath('files.0.name', 'daily note.txt');
});

test('the shell rejects path traversal outside its virtual root', function () {
    File::put($this->outsideFile, 'outside');

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.directory', ['path' => '/root/../../testing']))
        ->assertUnprocessable();

    expect(File::exists($this->outsideFile))->toBeTrue();
});

test('the shell preview returns source text without executing active content', function () {
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'note.html', '<script>do-not-run()</script>');

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.preview', ['path' => '/root/note.html']))
        ->assertOk()
        ->assertJsonPath('name', 'note.html')
        ->assertJsonPath('preview', '<script>do-not-run()</script>');

    $response = $this->get(route('shell.stream', ['path' => '/root/note.html']));
    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
});

test('the shell extracts docx text without relying on the file extension or rendering active content', function () {
    $path = $this->shellRoot.DIRECTORY_SEPARATOR.'report.data';
    $archive = new ZipArchive();
    expect($archive->open($path, ZipArchive::CREATE))->toBeTrue();
    $archive->addFromString('word/document.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
            <w:body>
                <w:p><w:r><w:t>Quarterly report</w:t></w:r></w:p>
                <w:p><w:r><w:t>&lt;script&gt;do-not-run()&lt;/script&gt;</w:t></w:r></w:p>
            </w:body>
        </w:document>
        XML);
    $archive->close();

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.preview', ['path' => '/root/report.data']))
        ->assertOk()
        ->assertJsonPath('extracted', true)
        ->assertJsonPath('preview', "Quarterly report\n<script>do-not-run()</script>");
});

test('the shell keeps the download fallback for archives without extractable document text', function () {
    $path = $this->shellRoot.DIRECTORY_SEPARATOR.'unsupported.docx';
    $archive = new ZipArchive();
    expect($archive->open($path, ZipArchive::CREATE))->toBeTrue();
    $archive->addFromString('readme.txt', 'not a Word document');
    $archive->close();

    $this->actingAs(User::factory()->create())
        ->getJson(route('shell.preview', ['path' => '/root/unsupported.docx']))
        ->assertOk()
        ->assertJsonPath('preview', null)
        ->assertJsonPath('extracted', false);
});

test('shell file deletion requires affirmative confirmation and removes only private files', function () {
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'remove-me.txt', 'private');
    $this->actingAs(User::factory()->create());

    $this->deleteJson(route('shell.file.destroy'), ['path' => '/root/remove-me.txt'])
        ->assertUnprocessable();

    expect(File::exists($this->shellRoot.DIRECTORY_SEPARATOR.'remove-me.txt'))->toBeTrue();

    $this->deleteJson(route('shell.file.destroy'), [
        'path' => '/root/remove-me.txt',
        'confirmation' => 'yes',
    ])
        ->assertOk()
        ->assertJsonPath('deleted', 'remove-me.txt');

    expect(File::exists($this->shellRoot.DIRECTORY_SEPARATOR.'remove-me.txt'))->toBeFalse();
});

test('the shell creates directories inside the virtual root', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('shell.directory.create'), ['path' => '/root/new-folder'])
        ->assertCreated()
        ->assertJsonPath('created', 'new-folder');

    expect(File::isDirectory($this->shellRoot.DIRECTORY_SEPARATOR.'new-folder'))->toBeTrue();
});

test('the shell copies files and moves entries without overwriting existing destinations', function () {
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'original.txt', 'source contents');
    File::ensureDirectoryExists($this->shellRoot.DIRECTORY_SEPARATOR.'target');
    $this->actingAs(User::factory()->create());

    $this->postJson(route('shell.copy'), [
        'source' => '/root/original.txt',
        'destination' => '/root/copied.txt',
    ])->assertCreated()
        ->assertJsonPath('copied', '/root/copied.txt');

    expect(File::get($this->shellRoot.DIRECTORY_SEPARATOR.'copied.txt'))->toBe('source contents');

    $this->postJson(route('shell.move'), [
        'source' => '/root/copied.txt',
        'destination' => '/root/target',
    ])->assertOk()
        ->assertJsonPath('moved', '/root/target/copied.txt');

    expect(File::exists($this->shellRoot.DIRECTORY_SEPARATOR.'copied.txt'))->toBeFalse()
        ->and(File::get($this->shellRoot.DIRECTORY_SEPARATOR.'target'.DIRECTORY_SEPARATOR.'copied.txt'))->toBe('source contents');

    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'target'.DIRECTORY_SEPARATOR.'copied.txt', 'do not overwrite');
    $this->postJson(route('shell.copy'), [
        'source' => '/root/original.txt',
        'destination' => '/root/target/copied.txt',
    ])->assertConflict();

    expect(File::get($this->shellRoot.DIRECTORY_SEPARATOR.'target'.DIRECTORY_SEPARATOR.'copied.txt'))->toBe('do not overwrite');
});

test('the shell copies directories recursively but rejects copying into descendants', function () {
    File::ensureDirectoryExists($this->shellRoot.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'nested');
    File::put($this->shellRoot.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'note.txt', 'nested contents');
    $this->actingAs(User::factory()->create());

    $this->postJson(route('shell.copy'), [
        'source' => '/root/source',
        'destination' => '/root/copied',
    ])->assertCreated()
        ->assertJsonPath('copied', '/root/copied');

    expect(File::get($this->shellRoot.DIRECTORY_SEPARATOR.'copied'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'note.txt'))
        ->toBe('nested contents');

    $this->postJson(route('shell.copy'), [
        'source' => '/root/source',
        'destination' => '/root/source/child',
    ])->assertConflict();
});
