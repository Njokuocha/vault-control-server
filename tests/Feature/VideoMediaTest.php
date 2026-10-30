<?php

use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\File as VaultFile;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function completedVideoForMedia(string $name = 'case-video.mp4', bool $withPreview = false): VideoUpload
{
    $bytes = pack('N', 24).'ftypmp42'.pack('N', 0).pack('N', 0).'mp42'.str_repeat("\0", 120);
    $path = '2026/10/'.Str::uuid().'.mp4';
    Storage::disk('vault')->put($path, $bytes);
    $playablePath = $withPreview ? 'renditions/videos/'.Str::uuid().'.mp4' : null;
    $posterPath = $withPreview ? 'renditions/posters/'.Str::uuid().'.jpg' : null;
    if ($playablePath && $posterPath) {
        Storage::disk('vault')->put($playablePath, 'browser compatible video bytes');
        Storage::disk('vault')->put($posterPath, 'jpeg poster bytes');
    }

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
        'video_playable_path' => $playablePath,
        'video_poster_path' => $posterPath,
        'video_duration' => $withPreview ? 37 : null,
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
    $upload = completedVideoForMedia('profile-video.mp4', true);
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
    $this->get(route('people.images.poster', ['person' => $person, 'image' => $media->id]))
        ->assertForbidden();

    app(SettingService::class)->set('person_image_pin_hash', Hash::make('0427'));
    $this->postJson(route('people.images.unlock', $person), ['pin' => '0427'])->assertOk();
    $this->get(route('people.image', ['person' => $person, 'image' => $media->id]), ['Range' => 'bytes=0-5'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-5/'.strlen(Storage::disk('vault')->get($media->playable_path)));
    $this->get(route('people.images.poster', ['person' => $person, 'image' => $media->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
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

    $case = InvestigationCase::query()->firstOrFail();
    $media = CaseImage::query()->firstOrFail();
    expect($media->type)->toBe('video')
        ->and($upload->fresh()->attached_at)->not->toBeNull();

    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('data-video-viewer', false)
        ->assertSee('data-case-video-thumbnail', false)
        ->assertSee('witness-video.mp4');
    $this->get(route('cases.edit', $case))
        ->assertOk()
        ->assertSee('case-evidence-video-mark--centered', false);
    $this->get(route('cases.image', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

test('case PDF documents open inline while the explicit download route remains an attachment', function () {
    $bytes = "%PDF-1.7\nprivate evidence PDF";
    $path = '2026/10/case-evidence.pdf';
    Storage::disk('vault')->put($path, $bytes);
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'case-evidence.pdf',
        'stored_path' => $path,
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'category' => 'document',
        'upload_method' => 'manual',
        'size' => strlen($bytes),
        'checksum' => hash('sha256', $bytes),
        'is_editable' => false,
    ]);
    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-PDF-VIEW',
        'title' => 'PDF evidence case',
    ]);
    $category = $case->categories()->create(['title' => 'Documents']);
    $media = $category->images()->create([
        'file_id' => $file->id,
        'path' => $path,
        'original_name' => 'case-evidence.pdf',
        'type' => 'document',
    ]);
    $this->actingAs(User::factory()->create());

    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('href="'.route('cases.image', ['caseFile' => $case, 'image' => $media]).'"', false)
        ->assertSee('aria-label="View case-evidence.pdf"', false);
    $this->get(route('cases.edit', $case))
        ->assertOk()
        ->assertSee('target="_blank" rel="noopener"', false);

    $this->get(route('cases.image', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename=case-evidence.pdf');
    $this->get(route('cases.images.download', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=case-evidence.pdf');
});

test('person galleries defer a protected video thumbnail until image access is unlocked', function () {
    $upload = completedVideoForMedia('profile-video.mp4');
    $operator = User::factory()->create();
    $this->actingAs($operator)
        ->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('data-protected-video-thumbnail', false)
        ->assertSee('data-video-pin-required', false);

    $fileManager = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);
    $this->actingAs($fileManager, 'web')
        ->get(route('fm.person-media.show', $person))
        ->assertOk()
        ->assertSee('data-fm-protected-video-thumbnail', false);
});

test('case video posters fall back to the private file poster when the case poster path is stale', function () {
    $upload = completedVideoForMedia('witness-video.mp4', true);
    $this->actingAs(User::factory()->create())
        ->post(route('cases.store'), [
            'title' => 'Video poster fallback case',
            'categories' => [[
                'title' => 'Witnesses',
                'video_upload_tokens' => [$upload->token],
            ]],
        ])
        ->assertRedirect();

    $case = InvestigationCase::query()->firstOrFail();
    $media = CaseImage::query()->firstOrFail();
    $media->update(['poster_path' => 'missing/case-poster.jpg']);

    $this->get(route('cases.images.poster', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('legacy case image files support private byte-range delivery', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-RANGE-01',
        'title' => 'Range delivery case',
    ]);
    $category = CaseCategory::query()->create([
        'case_file_id' => $case->id,
        'title' => 'Photographs',
        'sort_order' => 0,
    ]);
    $path = "cases/{$case->id}/image.png";
    Storage::disk('vault')->put($path, '0123456789');
    $image = CaseImage::query()->create([
        'case_category_id' => $category->id,
        'path' => $path,
        'original_name' => 'image.png',
        'type' => 'image',
    ]);

    $response = $this->actingAs($staff, 'web')
        ->get(route('cfm.cases.image', ['caseFile' => $case, 'image' => $image]), ['Range' => 'bytes=1-4']);

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 1-4/10');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    ob_start();
    $response->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('1234');
});

test('PIN-protected legacy person images support private byte-range delivery', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-RANGE-01',
        'name' => 'Range delivery person',
    ]);
    $path = "people/{$person->id}/image.png";
    Storage::disk('vault')->put($path, '0123456789');
    $image = PersonImage::query()->create([
        'person_id' => $person->id,
        'path' => $path,
        'original_name' => 'image.png',
        'type' => 'image',
    ]);
    app(SettingService::class)->set('person_image_pin_hash', Hash::make('0427'));
    $this->actingAs($staff, 'web')
        ->postJson(route('pm.people.images.unlock', $person), ['pin' => '0427'])
        ->assertOk();

    $response = $this->get(route('pm.people.image', ['person' => $person, 'image' => $image->id]), [
        'Range' => 'bytes=3-6',
    ]);

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 3-6/10');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    ob_start();
    $response->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('3456');
});

test('optional private renditions and posters are served through authorized media routes', function () {
    $upload = completedVideoForMedia('processed-video.mp4', true);
    $this->actingAs(User::factory()->create())
        ->post(route('cases.store'), [
            'title' => 'Processed video case',
            'categories' => [[
                'title' => 'Processed evidence',
                'video_upload_tokens' => [$upload->token],
            ]],
        ])
        ->assertRedirect();

    $case = InvestigationCase::query()->firstOrFail();
    $media = CaseImage::query()->firstOrFail();
    expect($media->playable_path)->not->toBeNull()
        ->and($media->poster_path)->not->toBeNull()
        ->and($media->duration)->toBe(37);

    $renditionResponse = $this->get(route('cases.image', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
    expect(realpath($renditionResponse->baseResponse->getFile()->getPathname()))
        ->toBe(realpath(Storage::disk('vault')->path($media->playable_path)));

    $posterResponse = $this->get(route('cases.images.poster', ['caseFile' => $case, 'image' => $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect(realpath($posterResponse->baseResponse->getFile()->getPathname()))
        ->toBe(realpath(Storage::disk('vault')->path($media->poster_path)));
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
    $upload = completedVideoForMedia('case-video.mp4', true);
    $this->actingAs(User::factory()->create())
        ->post(route('people.store'), ['video_upload_tokens' => [$upload->token]])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $media = PersonImage::query()->firstOrFail();
    $filePath = $media->path;
    $playablePath = $media->playable_path;
    $posterPath = $media->poster_path;

    $this->deleteJson(route('people.images.destroy', ['person' => $person, 'image' => $media]))
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    Storage::disk('vault')->assertMissing($filePath);
    Storage::disk('vault')->assertMissing($playablePath);
    Storage::disk('vault')->assertMissing($posterPath);
    expect(VaultFile::query()->count())->toBe(0)
        ->and(PersonImage::query()->count())->toBe(0);
});
