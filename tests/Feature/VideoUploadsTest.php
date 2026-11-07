<?php

use App\Jobs\FinalizeVideoUploadJob;
use App\Models\File as VaultFile;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\FileUploadService;
use App\Services\SettingService;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vault.max_upload_mb' => 10,
        'vault.chunk_size_mb' => 1,
        'vault.upload_disk' => 'vault',
    ]);
    Storage::fake('local');
    Storage::fake('vault');
    app(SettingService::class)->set('max_video_upload_mb', '0');
});

test('guests cannot initialize private video uploads', function () {
    $this->postJson(route('video-uploads.initialize'), [
        'name' => 'evidence.mp4',
        'size' => 100,
    ])->assertUnauthorized();
});

test('authenticated video upload initialization returns bounded private chunk metadata', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('video-uploads.initialize'), [
            'name' => 'evidence.mp4',
            'size' => 100,
        ])
        ->assertCreated()
        ->assertJsonPath('chunk_size', 1048576)
        ->assertJsonPath('chunk_count', 1);

    expect($response->json())->not->toHaveKey('stored_path')
        ->not->toHaveKey('private_path');

    $upload = VideoUpload::query()->firstOrFail();
    expect($upload->status)->toBe('uploading')
        ->and($upload->original_name)->toBe('evidence.mp4');
});

test('video chunks are stored privately and queue finalization only after every chunk arrives', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $bytes = str_repeat('v', 1048576).str_repeat('w', 50);
    $init = $this->postJson(route('video-uploads.initialize'), [
        'name' => 'evidence.mp4',
        'size' => strlen($bytes),
    ])->assertCreated()->json();

    $this->post(route('video-uploads.chunk', ['videoUpload' => $init['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', substr($bytes, 0, 1048576)),
    ])->assertOk()->assertJsonPath('status', 'uploading');

    Storage::disk('local')->assertExists('vault-chunks/'.$init['token'].'/0.part');
    Queue::assertNothingPushed();

    $this->post(route('video-uploads.chunk', ['videoUpload' => $init['token'], 'index' => 1]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', substr($bytes, 1048576)),
    ])->assertOk()->assertJsonPath('status', 'processing');

    Queue::assertPushed(FinalizeVideoUploadJob::class);
    expect(VideoUpload::query()->where('token', $init['token'])->value('status'))->toBe('processing');
});

test('video upload rejects an oversized declared total before accepting chunks', function () {
    config(['vault.max_upload_mb' => 1]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('video-uploads.initialize'), [
            'name' => 'oversized.mp4',
            'size' => 1048577,
        ])
        ->assertStatus(413);

    expect(VideoUpload::query()->count())->toBe(0);
});

test('cancelling an active video upload removes private partial chunks', function () {
    $this->actingAs(User::factory()->create());
    $init = $this->postJson(route('video-uploads.initialize'), [
        'name' => 'evidence.mp4',
        'size' => 100,
    ])->assertCreated()->json();

    Storage::disk('local')->put('vault-chunks/'.$init['token'].'/0.part', str_repeat('x', 100));
    $this->deleteJson(route('video-uploads.cancel', $init['token']))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    Storage::disk('local')->assertMissing('vault-chunks/'.$init['token'].'/0.part');
    expect(VideoUpload::query()->where('token', $init['token'])->value('status'))->toBe('cancelled');
});

test('failed uploads can be retried and their previous chunks are discarded', function () {
    $this->actingAs(User::factory()->create());
    $upload = VideoUpload::query()->create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'original_name' => 'evidence.mp4',
        'total_size' => 100,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'failed',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('vault-chunks/'.$upload->token.'/0.part', str_repeat('x', 100));

    $this->postJson(route('video-uploads.retry', $upload->token))
        ->assertOk()
        ->assertJsonPath('status', 'uploading');

    Storage::disk('local')->assertMissing('vault-chunks/'.$upload->token.'/0.part');
    expect($upload->fresh()->status)->toBe('uploading');
});

test('finalization stores video bytes as a private vault file and completes its upload record', function () {
    $finder = new ExecutableFinder;
    $ffmpeg = $finder->find('ffmpeg', config('vault.ffmpeg_path'));
    $ffprobe = $finder->find('ffprobe', config('vault.ffprobe_path'));
    if (($ffmpeg === null) !== ($ffprobe === null)) {
        $this->markTestSkipped('Configure both FFmpeg tools, or neither, for this fixture.');
    }

    $video = pack('N', 24).'ftypmp42'.pack('N', 0).pack('N', 0).'mp42'.str_repeat("\0", 1100);
    if ($ffmpeg !== null && $ffprobe !== null) {
        $directory = storage_path('framework/testing/video-upload-'.\Illuminate\Support\Str::uuid());
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the video upload test directory.');
        }
        $inputPath = $directory.DIRECTORY_SEPARATOR.'sample.mp4';

        try {
            $generator = new Process([
                $ffmpeg,
                '-nostdin', '-v', 'error', '-y',
                '-f', 'lavfi',
                '-i', 'color=c=black:s=160x90:d=2',
                '-an',
                '-c:v', 'libx264',
                '-preset', 'ultrafast',
                '-pix_fmt', 'yuv420p',
                '-movflags', '+faststart',
                $inputPath,
            ]);
            $generator->setTimeout(120);
            $generator->mustRun();
            $video = file_get_contents($inputPath);
            if ($video === false) {
                throw new RuntimeException('Unable to read the generated video upload test fixture.');
            }
        } finally {
            if (is_file($inputPath)) {
                unlink($inputPath);
            }
            rmdir($directory);
        }
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($video);
    expect($mimeType)->toBe('video/mp4');

    $upload = VideoUpload::query()->create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'original_name' => 'evidence.mp4',
        'total_size' => strlen($video),
        'chunk_size' => strlen($video),
        'chunk_count' => 1,
        'status' => 'processing',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('vault-chunks/'.$upload->token.'/0.part', $video);

    (new FinalizeVideoUploadJob($upload->id))->handle(app(FileUploadService::class), app(VideoProcessingService::class));

    $upload->refresh();
    $file = VaultFile::query()->findOrFail($upload->file_id);
    expect($upload->status)->toBe('completed')
        ->and($file->category)->toBe('video')
        ->and($file->mime_type)->toBe('video/mp4');
    Storage::disk('vault')->assertExists($file->stored_path);
    if ($ffmpeg !== null && $ffprobe !== null) {
        expect($file->video_playable_path)->toBeString()
            ->and($file->video_poster_path)->toBeString()
            ->and($file->video_duration)->toBe(2);
        Storage::disk('vault')->assertExists($file->video_playable_path);
        Storage::disk('vault')->assertExists($file->video_poster_path);
    } else {
        expect($file->video_playable_path)->toBeNull()
            ->and($file->video_poster_path)->toBeNull();
    }
    Storage::disk('local')->assertMissing('vault-chunks/'.$upload->token.'/0.part');
});

test('video finalization rejects non-video content even when its name has a video extension', function () {
    $upload = VideoUpload::query()->create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'original_name' => 'disguised.mp4',
        'total_size' => strlen('not a video'),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'processing',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('vault-chunks/'.$upload->token.'/0.part', 'not a video');

    expect(fn () => (new FinalizeVideoUploadJob($upload->id))->handle(app(FileUploadService::class), app(VideoProcessingService::class)))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect($upload->fresh()->status)->toBe('failed')
        ->and(VaultFile::query()->count())->toBe(0);
    expect(Storage::disk('vault')->allFiles())->toBe([]);
});
