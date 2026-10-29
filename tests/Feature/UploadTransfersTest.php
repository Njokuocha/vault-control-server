<?php

use App\Events\UploadTransferStatusChanged;
use App\Jobs\FinalizeUploadTransferJob;
use App\Livewire\CaseDossier;
use App\Models\File as VaultFile;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\RecordUploadAttachmentService;
use App\Services\SettingService;
use App\Services\UploadTransferStatus;
use App\Services\VideoProcessingService;
use App\Support\RecordAttachmentTypes;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vault.max_upload_mb' => 10,
        'vault.max_video_upload_mb' => 0,
        'vault.chunk_size_mb' => 1,
        'vault.upload_disk' => 'vault',
    ]);
    app(SettingService::class)->set('max_upload_mb', '10');
    app(SettingService::class)->set('max_video_upload_mb', '0');
    Storage::fake('local');
    Storage::fake('vault');
});

test('guests cannot read or initialize private upload transfers', function () {
    $this->getJson(route('upload-transfers.index'))->assertUnauthorized();
    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'record.txt',
        'size' => 20,
    ])->assertUnauthorized();
});

test('upload transfer initialization applies the global limit and returns chunk endpoints', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'record.txt',
        'size' => 20,
    ])->assertCreated()
        ->assertJsonPath('chunk_size', 1048576)
        ->assertJsonPath('chunk_count', 1)
        ->assertJsonPath('status', 'uploading');

    expect($response->json())->not->toHaveKey('stored_path')
        ->not->toHaveKey('private_path');

    $transfer = UploadTransfer::query()->firstOrFail();
    expect($transfer->status)->toBe('uploading')
        ->and($transfer->user_id)->toBe(auth()->id());

    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'oversized.txt',
        'size' => 10 * 1024 * 1024 + 1,
    ])->assertStatus(413);
});

test('upload chunks stay private and finalization is queued only when all chunks arrive', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $bytes = str_repeat('a', 1048576).'tail';
    $response = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'record.txt',
        'size' => strlen($bytes),
    ])->assertCreated()->json();

    $this->post(route('upload-transfers.chunk', ['token' => $response['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', substr($bytes, 0, 1048576)),
    ])->assertOk()->assertJsonPath('status', 'uploading');

    Storage::disk('local')->assertExists('upload-transfer-chunks/'.$response['token'].'/0.part');
    Queue::assertNotPushed(FinalizeUploadTransferJob::class);

    $this->post(route('upload-transfers.chunk', ['token' => $response['token'], 'index' => 1]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', substr($bytes, 1048576)),
    ])->assertOk()->assertJsonPath('status', 'pending');

    Queue::assertPushed(FinalizeUploadTransferJob::class);
    expect(UploadTransfer::query()->where('token', $response['token'])->value('status'))->toBe('pending');
});

test('transfer snapshot reports owner totals and received chunk indexes for resume', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);
    $bytes = str_repeat('a', 1048576).'tail';
    $response = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'resume.txt',
        'size' => strlen($bytes),
    ])->assertCreated()->json();
    $this->post(route('upload-transfers.chunk', ['token' => $response['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', substr($bytes, 0, 1048576)),
    ])->assertOk();

    $snapshot = $this->getJson(route('upload-transfers.index'))
        ->assertOk()
        ->assertJsonPath('total_uploads', 1)
        ->assertJsonPath('pending_uploads', 1)
        ->assertJsonPath('uploads.0.file_type', 'txt')
        ->assertJsonPath('uploads.0.received_chunks.0', 0)
        ->assertJsonPath('uploads.0.progress', 99);
    expect($snapshot->json('uploads.0.uploaded_at'))->toBeString()
        ->and($snapshot->json('uploads.0.duration_seconds'))->toBeNull();

    $otherTransfer = $otherUser->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'private-to-other.txt',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'uploading',
        'expires_at' => now()->addDay(),
    ]);
    expect($snapshot->json('uploads'))->toHaveCount(1)
        ->and($snapshot->json('uploads.0.token'))->toBe($response['token']);

    $this->getJson(route('upload-transfers.status', $otherTransfer->token))->assertNotFound();
    $this->deleteJson(route('upload-transfers.cancel', $otherTransfer->token))->assertNotFound();
});

test('queued finalization stores arbitrary files privately and publishes final status totals', function () {
    Queue::fake();
    Event::fake([UploadTransferStatusChanged::class]);
    $user = User::factory()->create();
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'private-record.txt',
        'total_size' => strlen('private record contents'),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', 'private record contents');

    (new FinalizeUploadTransferJob($transfer->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    $transfer->refresh();
    $file = VaultFile::query()->findOrFail($transfer->file_id);
    expect($transfer->status)->toBe('done')
        ->and($file->original_name)->toBe('private-record.txt')
        ->and($file->category)->toBe('text')
        ->and($file->upload_method)->toBe('manual');
    $this->actingAs($user)->getJson(route('upload-transfers.status', $transfer->token))
        ->assertOk()
        ->assertJsonPath('file_type', 'txt')
        ->assertJsonPath('file_mime_type', 'text/plain')
        ->assertJsonPath('preview_url', route('files.preview', ['file' => $file->uuid]))
        ->assertJsonPath('status', 'done');
    Storage::disk('vault')->assertExists($file->stored_path);
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'pending'
        && $event->pendingUploads === 1);
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'processing'
        && $event->pendingUploads === 1);
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'done'
        && $event->pendingUploads === 0);
});

test('record attachment initialization validates intent ownership and filename extension', function () {
    $user = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $profileManager = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = Person::query()->create(['public_id' => 'USR-ATTACH-01']);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-ATTACH-01',
        'title' => 'Evidence case',
    ]);
    $category = $caseFile->categories()->create(['title' => 'Documents']);
    $this->actingAs($user);

    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'record.pdf',
        'size' => 20,
        'attachment' => [
            'purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
            'record_id' => $caseFile->id,
            'target_id' => $category->id,
            'commit_immediately' => true,
        ],
    ])->assertCreated()
        ->assertJsonPath('status', 'uploading');
    expect(UploadTransfer::query()->where('original_name', 'record.pdf')->value('committed_at'))->not->toBeNull();

    $this->actingAs($profileManager);
    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'script.php',
        'size' => 20,
        'attachment' => [
            'purpose' => RecordAttachmentTypes::PERSON_GALLERY,
            'draft_token' => (string) Str::uuid(),
        ],
    ])->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'This file extension is not allowed for the selected record. Choose a supported file type.');

    $this->actingAs($user);
    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'evidence.pdf',
        'size' => 20,
        'attachment' => [
            'purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
            'record_id' => $caseFile->id,
            'target_id' => 99999,
        ],
    ])->assertNotFound();

    $this->actingAs($profileManager);
    $personTransfer = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'portrait.jpg',
        'size' => 20,
        'attachment' => [
            'purpose' => RecordAttachmentTypes::PERSON_MAIN_IMAGE,
            'record_id' => $person->id,
        ],
    ])->assertCreated();
    expect(UploadTransfer::query()->where('original_name', 'portrait.jpg')->value('committed_at'))->toBeNull();
});

test('record attachment intents are limited to the assigned department', function () {
    $caseManager = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $profileManager = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);

    $this->actingAs($caseManager)->postJson(route('upload-transfers.initialize'), [
    'name' => 'portrait.jpg',
    'size' => 20,
    'attachment' => [
        'purpose' => RecordAttachmentTypes::PERSON_GALLERY,
        'draft_token' => (string) Str::uuid(),
    ],
    ])->assertForbidden();

    $this->actingAs($profileManager)->postJson(route('upload-transfers.initialize'), [
    'name' => 'evidence.pdf',
    'size' => 20,
    'attachment' => [
        'purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
        'draft_token' => (string) Str::uuid(),
        'target_key' => (string) Str::uuid(),
    ],
    ])->assertForbidden();
});

test('only file managers and administrators can initialize standalone archive uploads', function () {
    foreach (['cfm', 'pm'] as $department) {
    $staff = User::factory()->create([
        'department' => $department,
        'account_status' => 'active',
    ]);

    $this->actingAs($staff)->postJson(route('upload-transfers.initialize'), [
        'name' => 'unattached.pdf',
        'size' => 20,
    ])->assertForbidden();
    }

    $fileManager = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);

    $this->actingAs($fileManager)->postJson(route('upload-transfers.initialize'), [
        'name' => 'file-manager-upload.txt',
        'size' => 20,
    ])->assertCreated()
        ->assertJsonPath('status', 'uploading');

    expect(UploadTransfer::query()->where('user_id', $fileManager->id)->count())->toBe(1);
});

test('uncommitted record transfers remain pending until the form binds them', function () {
    $user = User::factory()->create();
    $person = Person::query()->create(['public_id' => 'USR-DRAFT-01']);
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::PERSON_GALLERY,
        'record_type' => 'person',
        'draft_token' => (string) Str::uuid(),
        'original_name' => 'portrait.png',
        'total_size' => 8,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', "\x89PNG\r\n\x1a\n");

    (new FinalizeUploadTransferJob($transfer->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    expect($transfer->fresh()->status)->toBe('pending')
        ->and($transfer->fresh()->file_id)->toBeNull()
        ->and($person->images()->count())->toBe(0)
        ->and(VaultFile::query()->count())->toBe(0);
    Storage::disk('local')->assertExists('upload-transfer-chunks/'.$transfer->token.'/0.part');
});

test('a committed case evidence transfer finalizes and attaches the detected document type', function () {
    $user = User::factory()->create();
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-PDF-ATTACH',
        'title' => 'PDF evidence',
    ]);
    $category = $caseFile->categories()->create(['title' => 'Reports']);
    $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
        'record_type' => 'case',
        'record_id' => $caseFile->id,
        'target_id' => $category->id,
        'committed_at' => now(),
        'original_name' => 'report.pdf',
        'total_size' => strlen($contents),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', $contents);

    (new FinalizeUploadTransferJob($transfer->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    $transfer->refresh();
    $file = VaultFile::query()->findOrFail($transfer->file_id);
    $attachment = $category->images()->firstOrFail();
    expect($transfer->status)->toBe('done')
        ->and($transfer->attached_at)->not->toBeNull()
        ->and($attachment->type)->toBe('document')
        ->and($attachment->file_id)->toBe($file->id)
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->category)->toBe('pdf');
});

test('uploads started after case creation appear in the saved evidence category', function () {
    Queue::fake();
    $user = User::factory()->create(['department' => 'cfm', 'account_status' => 'active']);
    $targetKey = (string) Str::uuid();
    $this->actingAs($user);

    $caseResponse = $this->postJson(route('cfm.cases.store'), [
        'title' => 'Deferred upload case',
        'categories' => [
            ['title' => 'Reports', 'description' => 'Evidence reports', 'upload_target_key' => $targetKey],
        ],
    ])->assertOk();
    $recordId = $caseResponse->json('record_id');
    $categoryId = $caseResponse->json('upload_targets.'.$targetKey);
    $caseFile = InvestigationCase::query()->findOrFail($recordId);
    $dossier = Livewire::test(CaseDossier::class, ['caseFile' => $caseFile])
        ->assertSee('No images in this category.');
    $this->get(route('cfm.cases.show', $caseFile))
        ->assertOk()
        ->assertSee('No images in this category.');

    $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
    $transfer = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'deferred-report.pdf',
        'size' => strlen($contents),
        'attachment' => [
            'purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
            'record_id' => $recordId,
            'target_id' => $categoryId,
            'commit_immediately' => true,
        ],
    ])->assertCreated()->json();

    $this->post(route('upload-transfers.chunk', ['token' => $transfer['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', $contents),
    ])->assertOk()->assertJsonPath('status', 'pending');

    $upload = UploadTransfer::query()->where('token', $transfer['token'])->firstOrFail();
    (new FinalizeUploadTransferJob($upload->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    $dossier->dispatch('refresh-case-dossier', caseId: $recordId)
        ->assertSee('deferred-report.pdf')
        ->assertDontSee('No images in this category.');
    $this->get(route('cfm.cases.show', $caseFile->fresh()))
        ->assertOk()
        ->assertSee('deferred-report.pdf')
        ->assertDontSee('No images in this category.');
    $image = $caseFile->categories()->firstOrFail()->images()->where('original_name', 'deferred-report.pdf')->firstOrFail();
    $upload = $upload->fresh();
    $status = $this->getJson(route('upload-transfers.status', $upload->token))
        ->assertOk()
        ->assertJsonPath('preview_url', route('cfm.cases.evidence-preview', [
            'caseFile' => $caseFile,
            'file' => $upload->file->uuid,
        ]));
    $this->getJson($status->json('preview_url'))
        ->assertOk()
        ->assertJsonPath('stream_url', route('cfm.cases.evidence-stream', [
            'caseFile' => $caseFile,
            'file' => $upload->file->uuid,
        ]))
        ->assertJsonPath('download_url', route('cfm.cases.images.download', [
            'caseFile' => $caseFile,
            'image' => $image,
        ]));
    $otherCase = InvestigationCase::query()->create([
        'public_id' => 'CSE-CFM-OTHER',
        'title' => 'Unrelated case',
    ]);
    $this->getJson(route('cfm.cases.evidence-preview', [
        'caseFile' => $otherCase,
        'file' => $upload->file->uuid,
    ]))->assertNotFound();
    expect($image->exists)->toBeTrue();
});

test('gallery uploads started after person creation attach to the saved profile', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $personResponse = $this->postJson(route('people.store'), [
        'name' => 'Deferred upload profile',
    ])->assertOk();
    $person = Person::query()->findOrFail($personResponse->json('record_id'));
    $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    $transfer = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'profile-evidence.png',
        'size' => strlen($contents),
        'attachment' => [
            'purpose' => RecordAttachmentTypes::PERSON_GALLERY,
            'record_id' => $person->id,
            'commit_immediately' => true,
        ],
    ])->assertCreated()->json();

    $this->post(route('upload-transfers.chunk', ['token' => $transfer['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', $contents),
    ])->assertOk()->assertJsonPath('status', 'pending');

    $upload = UploadTransfer::query()->where('token', $transfer['token'])->firstOrFail();
    (new FinalizeUploadTransferJob($upload->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    expect($person->images()->where('original_name', 'profile-evidence.png')->where('type', 'image')->exists())->toBeTrue();
});

test('gallery uploads started after person update attach to the existing profile', function () {
    Queue::fake();
    $user = User::factory()->create();
    $person = Person::query()->create([
        'public_id' => 'USR-DEFER-EDIT',
        'name' => 'Existing deferred profile',
    ]);
    $this->actingAs($user)
        ->putJson(route('people.update', $person), ['name' => 'Updated deferred profile'])
        ->assertOk()
        ->assertJsonPath('record_id', $person->id);

    $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    $transfer = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'updated-profile-evidence.png',
        'size' => strlen($contents),
        'attachment' => [
            'purpose' => RecordAttachmentTypes::PERSON_GALLERY,
            'record_id' => $person->id,
            'commit_immediately' => true,
        ],
    ])->assertCreated()->json();

    $this->post(route('upload-transfers.chunk', ['token' => $transfer['token'], 'index' => 0]), [
        'chunk' => UploadedFile::fake()->createWithContent('chunk.part', $contents),
    ])->assertOk()->assertJsonPath('status', 'pending');

    $upload = UploadTransfer::query()->where('token', $transfer['token'])->firstOrFail();
    (new FinalizeUploadTransferJob($upload->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    expect($person->images()->where('original_name', 'updated-profile-evidence.png')->where('type', 'image')->exists())->toBeTrue();
});

test('a committed person main-image transfer updates the primary image and file reference', function () {
    $user = User::factory()->create();
    $person = Person::query()->create(['public_id' => 'USR-MAIN-IMAGE']);
    $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::PERSON_MAIN_IMAGE,
        'record_type' => 'person',
        'record_id' => $person->id,
        'committed_at' => now(),
        'original_name' => 'portrait.png',
        'total_size' => strlen($contents),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', $contents);

    (new FinalizeUploadTransferJob($transfer->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    $person->refresh();
    $transfer->refresh();
    expect($transfer->error_message)->toBeNull()
        ->and($transfer->status)->toBe('done')
        ->and($transfer->file_id)->not->toBeNull()
        ->and($person->main_image_path)->toBe($transfer->file->stored_path)
        ->and($person->main_image_file_id)->toBe($transfer->file_id)
        ->and($transfer->attached_at)->not->toBeNull();
});

test('record attachment MIME validation rejects a mismatched detected type', function () {
    expect(fn () => app(RecordAttachmentTypes::class)->assertAllowedFile(
        'evidence.pdf',
        'text/plain',
        RecordAttachmentTypes::CASE_EVIDENCE,
    ))->toThrow(ValidationException::class);
});

test('record finalization immediately fails mismatched detected MIME and cleans its chunks', function () {
    $user = User::factory()->create();
    $person = Person::query()->create(['public_id' => 'USR-TYPE-FAIL']);
    $contents = 'This is not a PDF.';
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::PERSON_GALLERY,
        'record_type' => 'person',
        'record_id' => $person->id,
        'committed_at' => now(),
        'original_name' => 'not-a-video.mp4',
        'total_size' => strlen($contents),
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', $contents);

    (new FinalizeUploadTransferJob($transfer->id))->handle(
        app(FileUploadService::class),
        app(VideoProcessingService::class),
        app(UploadTransferStatus::class),
        app(RecordAttachmentTypes::class),
        app(RecordUploadAttachmentService::class),
    );

    expect($transfer->fresh()->status)->toBe('failed')
        ->and($transfer->fresh()->error_message)->toContain('detected file type')
        ->and(VaultFile::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token);
});

test('record forms bind draft uploads to the saved person and case evidence category', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $personDraft = (string) Str::uuid();
    $personTransfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::PERSON_GALLERY,
        'record_type' => 'person',
        'draft_token' => $personDraft,
        'original_name' => 'portrait.jpg',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    $this->post(route('people.store'), [
        'name' => 'Draft person',
        'record_upload_draft_token' => $personDraft,
        'gallery_upload_tokens' => [$personTransfer->token],
    ])->assertRedirect();

    $person = Person::query()->where('name', 'Draft person')->firstOrFail();
    expect($personTransfer->fresh()->record_type)->toBe('person')
        ->and($personTransfer->fresh()->record_id)->toBe($person->id)
        ->and($personTransfer->fresh()->committed_at)->not->toBeNull();
    Queue::assertPushed(FinalizeUploadTransferJob::class, fn (FinalizeUploadTransferJob $job): bool => $job->uploadTransferId === $personTransfer->id);

    $caseDraft = (string) Str::uuid();
    $categoryKey = (string) Str::uuid();
    $caseTransfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => RecordAttachmentTypes::CASE_EVIDENCE,
        'record_type' => 'case',
        'draft_token' => $caseDraft,
        'target_key' => $categoryKey,
        'original_name' => 'evidence.pdf',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    $this->post(route('cases.store'), [
        'title' => 'Draft case',
        'record_upload_draft_token' => $caseDraft,
        'categories' => [[
            'title' => 'Reports',
            'upload_target_key' => $categoryKey,
            'upload_transfer_tokens' => [$caseTransfer->token],
        ]],
    ])->assertRedirect();

    $caseFile = InvestigationCase::query()->where('title', 'Draft case')->firstOrFail();
    $category = $caseFile->categories()->firstOrFail();
    expect($caseTransfer->fresh()->record_type)->toBe('case')
        ->and($caseTransfer->fresh()->record_id)->toBe($caseFile->id)
        ->and($caseTransfer->fresh()->target_id)->toBe($category->id)
        ->and($caseTransfer->fresh()->target_key)->toBeNull()
        ->and($caseTransfer->fresh()->committed_at)->not->toBeNull();
    Queue::assertPushed(FinalizeUploadTransferJob::class, fn (FinalizeUploadTransferJob $job): bool => $job->uploadTransferId === $caseTransfer->id);
});

test('person create supports JSON SPA submission while preserving the success message and destination', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('people.store'), [
            'name' => 'JSON-created person',
            'record_upload_draft_token' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Person profile created.')
        ->assertJsonPath('redirect_url', route('people.show', Person::query()->where('name', 'JSON-created person')->firstOrFail()));

    $this->actingAs(User::factory()->create())
        ->get(route('people.create'))
        ->assertOk()
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-person-document-input', false)
        ->assertSee('multiple', false)
        ->assertSee('ADD DOCUMENT')
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO')
        ->assertSee('data-record-main-image-input', false);

    $person = Person::query()->create([
        'public_id' => 'USR-STAGE3-EDIT',
        'name' => 'Existing person',
    ]);
    $this->actingAs(User::factory()->create())
        ->get(route('people.edit', $person))
        ->assertOk()
        ->assertSee('ADD DOCUMENT')
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO')
        ->assertSee('data-record-form-submit', false);
});

test('transfer cancellation removes partial chunks and terminal rows can be removed', function () {
    $this->actingAs($user = User::factory()->create());
    $response = $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'cancel.txt',
        'size' => 100,
    ])->assertCreated()->json();
    Storage::disk('local')->put('upload-transfer-chunks/'.$response['token'].'/0.part', str_repeat('x', 100));

    $this->deleteJson(route('upload-transfers.cancel', $response['token']))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$response['token'].'/0.part');

    $this->deleteJson(route('upload-transfers.remove', $response['token']))
        ->assertOk()
        ->assertJsonPath('status', 'removed');
    expect($user->uploadTransfers()->count())->toBe(0);
});

test('failed or cancelled upload transfers can restart from the beginning', function () {
    $user = User::factory()->create();
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'retry.txt',
        'total_size' => 100,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'failed',
        'error_message' => 'The last attempt failed.',
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', str_repeat('x', 100));
    $this->actingAs($user)
        ->postJson(route('upload-transfers.retry', $transfer->token))
        ->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonPath('received_chunks', []);

    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
    expect($transfer->fresh()->status)->toBe('uploading')
        ->and($transfer->fresh()->expires_at->isFuture())->toBeTrue();
});

test('expired incomplete transfers are marked failed and their chunks are pruned', function () {
    $user = User::factory()->create();
    $transfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'expired.txt',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'uploading',
        'expires_at' => now()->subMinute(),
    ]);
    $processingTransfer = $user->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'stuck-processing.txt',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'processing',
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', str_repeat('x', 20));
    Storage::disk('local')->put('upload-transfer-chunks/'.$processingTransfer->token.'/assembled.upload', str_repeat('x', 20));

    $this->artisan('vault:prune-upload-transfers')->assertSuccessful();

    expect($transfer->fresh()->status)->toBe('failed')
        ->and($transfer->fresh()->error_message)->toContain('expired')
        ->and($processingTransfer->fresh()->status)->toBe('failed')
        ->and($processingTransfer->fresh()->error_message)->toContain('expired');
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$processingTransfer->token);
});

test('upload transfer broadcasts are private to the matching authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $channels = app(BroadcastManager::class)
        ->connection()
        ->getChannels();

    expect($channels['uploads.{userId}']($user, $user->id))->toBeTrue()
        ->and($channels['uploads.{userId}']($user, $otherUser->id))->toBeFalse();

    $event = new UploadTransferStatusChanged(
        userId: $user->id,
        uploadId: 7,
        name: 'record.txt',
        size: 20,
        status: 'pending',
        totalUploads: 4,
        pendingUploads: 2,
    );
    expect($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastOn()[0]->name)->toBe('private-uploads.'.$user->id)
        ->and($event->broadcastWith())->toMatchArray([
            'upload_id' => 7,
            'status' => 'pending',
            'total_uploads' => 4,
            'pending_uploads' => 2,
        ]);
});
