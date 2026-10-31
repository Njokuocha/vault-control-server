<?php

use App\Jobs\DeleteImageThumbnailJob;
use App\Livewire\ReportWorkspace;
use App\Models\Admin;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\Report;
use App\Models\User;
use App\Services\ImageThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['vault.upload_disk' => 'vault']);
    Storage::fake('vault');
});

function imageThumbnailResponseBody($response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

test('image thumbnails are generated privately at reduced dimensions without replacing originals', function () {
    $ffmpeg = (new ExecutableFinder)->find('ffmpeg');
    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed.');
    }

    $temporaryDirectory = storage_path('framework/testing');
    if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
        throw new RuntimeException('Unable to create the test image directory.');
    }
    $sourceTemporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.png';

    try {
        $process = new Process([
            $ffmpeg,
            '-nostdin', '-v', 'error', '-y',
            '-f', 'lavfi',
            '-i', 'color=c=navy:s=1200x800',
            '-frames:v', '1',
            $sourceTemporaryPath,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        $sourcePath = 'reports/source/evidence.png';
        Storage::disk('vault')->put($sourcePath, file_get_contents($sourceTemporaryPath));
        $originalHash = hash_file('sha256', Storage::disk('vault')->path($sourcePath));

        $thumbnailPath = app(ImageThumbnailService::class)->generate($sourcePath);

        expect($thumbnailPath)->toBe(app(ImageThumbnailService::class)->thumbnailPath($sourcePath))
            ->and(Storage::disk('vault')->exists($thumbnailPath))->toBeTrue()
            ->and(hash_file('sha256', Storage::disk('vault')->path($sourcePath)))->toBe($originalHash);

        $dimensions = getimagesize(Storage::disk('vault')->path($thumbnailPath));
        expect($dimensions[0])->toBeLessThanOrEqual(480)
            ->and($dimensions[1])->toBeLessThan(800)
            ->and((new finfo(FILEINFO_MIME_TYPE))->file(Storage::disk('vault')->path($thumbnailPath)))->toBe('image/jpeg');

        (new DeleteImageThumbnailJob($sourcePath))->handle(app(ImageThumbnailService::class));
        Storage::disk('vault')->assertMissing($thumbnailPath);
        Storage::disk('vault')->assertExists($sourcePath);
    } finally {
        if (is_file($sourceTemporaryPath)) {
            unlink($sourceTemporaryPath);
        }
    }
});

test('report thumbnail requests retain report visibility checks and openers receive originals', function () {
    $owner = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $report = Report::query()->create([
        'uuid' => (string) Str::uuid(),
        'body' => 'Private report media.',
        'reporter_type' => $owner::class,
        'reporter_id' => $owner->id,
        'is_hidden' => true,
    ]);
    $sourcePath = "reports/{$report->id}/evidence.png";
    $thumbnailPath = app(ImageThumbnailService::class)->thumbnailPath($sourcePath);
    Storage::disk('vault')->put($sourcePath, 'original-image-bytes');
    Storage::disk('vault')->put($thumbnailPath, 'thumbnail-image-bytes');
    $media = $report->media()->create([
        'path' => $sourcePath,
        'original_name' => 'evidence.png',
        'mime_type' => 'image/png',
        'media_type' => 'image',
        'size' => 20,
        'sort_order' => 0,
    ]);

    $thumbnailResponse = $this->actingAs($owner, 'web')
        ->get(route('reports.media.thumbnail', [$report, $media]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect(imageThumbnailResponseBody($thumbnailResponse))->toBe('thumbnail-image-bytes');

    $originalResponse = $this->get(route('reports.media', [$report, $media]))->assertOk();
    expect(imageThumbnailResponseBody($originalResponse))->toBe('original-image-bytes');

    Storage::disk('vault')->delete($thumbnailPath);
    $fallbackResponse = $this->get(route('reports.media.thumbnail', [$report, $media]))->assertOk();
    expect(imageThumbnailResponseBody($fallbackResponse))->toBe('original-image-bytes');

    $this->actingAs($otherStaff, 'web')
        ->get(route('reports.media.thumbnail', [$report, $media]))
        ->assertNotFound();
});

test('report attachment uploads use UUID-scoped private paths and remain viewable', function () {
    $owner = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=', true);
    $upload = UploadedFile::fake()->createWithContent('evidence.png', $png);

    Livewire::actingAs($owner, 'web')
        ->test(ReportWorkspace::class)
        ->set('body', 'A report with an uploaded image.')
        ->set('uploads', [$upload])
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('body', 'A report with an uploaded image.')->firstOrFail();
    $media = $report->media()->firstOrFail();
    expect($media->path)->toStartWith('reports/'.$report->uuid.'/')
        ->and(Storage::disk('vault')->exists($media->path))->toBeTrue();

    $this->actingAs($owner, 'web')
        ->get(route('reports.media', [$report, $media]))
        ->assertOk();
});

test('case and PIN-protected person thumbnails serve private previews without changing original routes', function () {
    $admin = Admin::query()->create([
        'name' => 'Thumbnail Admin',
        'email' => 'thumbnail-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-THUMB-01',
        'title' => 'Thumbnail case',
    ]);
    $category = CaseCategory::query()->create([
        'case_file_id' => $caseFile->id,
        'title' => 'Evidence',
    ]);
    $caseImage = CaseImage::query()->create([
        'case_category_id' => $category->id,
        'path' => 'cases/evidence/case.png',
        'original_name' => 'case.png',
        'type' => 'image',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-THUMB-01',
        'name' => 'Protected Person',
    ]);
    $personImage = PersonImage::query()->create([
        'person_id' => $person->id,
        'path' => 'people/gallery/person.png',
        'original_name' => 'person.png',
        'type' => 'image',
    ]);
    foreach ([$caseImage->path, $personImage->path] as $sourcePath) {
        Storage::disk('vault')->put($sourcePath, 'original-image-bytes');
        Storage::disk('vault')->put(
            app(ImageThumbnailService::class)->thumbnailPath($sourcePath),
            'private-thumbnail',
        );
    }

    $caseResponse = $this->actingAs($admin, 'admin')
        ->get(route('cases.images.thumbnail', [$caseFile, $caseImage]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect(imageThumbnailResponseBody($caseResponse))->toBe('private-thumbnail');

    $user = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $this->actingAs($user, 'web')
        ->get(route('people.images.thumbnail', [$person, $personImage]))
        ->assertForbidden();
    $this->withSession(['unlocked_person_images' => $person->id]);
    $personResponse = $this->get(route('people.images.thumbnail', [$person, $personImage]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect(imageThumbnailResponseBody($personResponse))->toBe('private-thumbnail');
});
