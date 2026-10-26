<?php

use App\Models\File as VaultFile;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\User;
use App\Models\VideoUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function completedVideoForMedia(string $name = 'case-video.mp4'): VideoUpload
{
    $bytes = pack('N', 24).'ftypmp42'.pack('N', 0).pack('N', 0).'mp42'.str_repeat("\0", 120);
    $path = '2026/10/'.Str::uuid().'.mp4';
    Storage::disk('vault')->put($path, $bytes);
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => $name,
        'stored_path' => $path,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'category' => 'video',
        'upload_method' => 'manual',
        'size' => strlen($bytes),
        'checksum' => hash('sha256', $bytes),
        'is_editable' => false,
    ]);

    return VideoUpload::query()->create([
        'token' => (string) Str::uuid(),
        'original_name' => $name,
        'total_size' => strlen($bytes),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'completed',
        'file_id' => $file->id,
        'expires_at' => now()->addDay(),
    ]);
}

beforeEach(function (): void {
    config(['vault.upload_disk' => 'vault']);
    Storage::fake('vault');
});

test('completed private videos can be attached to person galleries and require the person PIN to stream', function () {
    $upload = completedVideoForMedia('profile-video.mp4');
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $media = PersonImage::query()->firstOrFail();
    expect($media->type)->toBe('video')
        ->and($media->file_id)->toBe($upload->file_id)
        ->and($upload->fresh()->attached_at)->not->toBeNull();

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('data-video-viewer', false)
        ->assertSee('data-video-pin-required', false);
    $this->get(route('people.image', ['person' => $person, 'image' => $media->id]))
        ->assertForbidden();

    app(\App\Services\SettingService::class)->set('person_image_pin_hash', \Illuminate\Support\Facades\Hash::make('0427'));
    $this->postJson(route('people.images.unlock', $person), ['pin' => '0427'])->assertOk();
    $this->get(route('people.image', ['person' => $person, 'image' => $media->id]), ['Range' => 'bytes=0-5'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-5/'.strlen(Storage::disk('vault')->get($media->path)));
});

test('completed private videos can be attached to any case evidence category and streamed', function () {
    $upload = completedVideoForMedia('witness-video.mp4');
    $this->actingAs(User::factory()->create())
        ->post(route('cases.store'), [
            'title' => 'Video evidence case',
            'categories' => [[
                'title' => 'Witnesses',
                'video_upload_tokens' => [$upload->token],
            ]],
        ])
        ->assertRedirect();

    $case = \App\Models\InvestigationCase::query()->firstOrFail();
    $media = \App\Models\CaseImage::query()->firstOrFail();
    expect($media->type)->toBe('video')
        ->and($upload->fresh()->attached_at)->not->toBeNull();

    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('data-video-viewer', false)
        ->assertSee('witness-video.mp4');
    $this->get(route('cases.image', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

test('videos cannot be attached if their upload has not completed or was already consumed', function () {
    $upload = completedVideoForMedia();
    $this->actingAs(User::factory()->create())
        ->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertRedirect();

    $this->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertSessionHasErrors('video_upload_tokens');

    expect(Person::query()->count())->toBe(1)
        ->and(PersonImage::query()->count())->toBe(1);
});

test('deleting gallery video media removes the underlying vault file', function () {
    $upload = completedVideoForMedia();
    $this->actingAs(User::factory()->create())
        ->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $media = PersonImage::query()->firstOrFail();
    $filePath = $media->path;

    $this->deleteJson(route('people.images.destroy', ['person' => $person, 'image' => $media]))
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    Storage::disk('vault')->assertMissing($filePath);
    expect(VaultFile::query()->count())->toBe(0)
        ->and(PersonImage::query()->count())->toBe(0);
});
