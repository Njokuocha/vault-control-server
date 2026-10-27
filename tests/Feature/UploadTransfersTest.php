<?php

use App\Events\UploadTransferStatusChanged;
use App\Jobs\FinalizeUploadTransferJob;
use App\Models\File as VaultFile;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\SettingService;
use App\Services\UploadTransferStatus;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        ->assertJsonPath('uploads.0.received_chunks.0', 0)
        ->assertJsonPath('uploads.0.progress', 99);

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
    );

    $transfer->refresh();
    $file = VaultFile::query()->findOrFail($transfer->file_id);
    expect($transfer->status)->toBe('done')
        ->and($file->original_name)->toBe('private-record.txt')
        ->and($file->category)->toBe('text')
        ->and($file->upload_method)->toBe('manual');
    Storage::disk('vault')->assertExists($file->stored_path);
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'pending'
        && $event->pendingUploads === 1);
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'processing'
        && $event->pendingUploads === 1);
    Event::assertDispatched(UploadTransferStatusChanged::class, fn (UploadTransferStatusChanged $event): bool => $event->status === 'done'
        && $event->pendingUploads === 0);
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
    $transfer = User::factory()->create()->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'original_name' => 'expired.txt',
        'total_size' => 20,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'uploading',
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', str_repeat('x', 20));

    $this->artisan('vault:prune-upload-transfers')->assertSuccessful();

    expect($transfer->fresh()->status)->toBe('failed')
        ->and($transfer->fresh()->error_message)->toContain('expired');
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
});

test('upload transfer broadcasts are private to the matching authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $channels = app(\Illuminate\Broadcasting\BroadcastManager::class)
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
