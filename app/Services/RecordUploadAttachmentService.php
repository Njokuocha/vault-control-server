<?php

namespace App\Services;

use App\Jobs\FinalizeUploadTransferJob;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Support\RecordAttachmentTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordUploadAttachmentService
{
    public function __construct(private readonly RecordAttachmentTypes $types) {}

    public function assertStandaloneUploadAllowed(User $user): void
    {
        abort_unless($this->isConfiguredAdministrator($user), 403);
    }

    public function validateIntent(User $user, array $intent): array
    {
        $purpose = $intent['purpose'] ?? null;
        if (! in_array($purpose, [
            RecordAttachmentTypes::PERSON_MAIN_IMAGE,
            RecordAttachmentTypes::PERSON_GALLERY,
            RecordAttachmentTypes::CASE_EVIDENCE,
        ], true)) {
            $this->invalidIntent();
        }

        $requiredDepartment = $purpose === RecordAttachmentTypes::CASE_EVIDENCE ? 'cfm' : 'pm';
        abort_unless($this->isConfiguredAdministrator($user) || $user->department === $requiredDepartment, 403);

        $recordType = $purpose === RecordAttachmentTypes::CASE_EVIDENCE ? 'case' : 'person';
        $recordId = isset($intent['record_id']) ? (int) $intent['record_id'] : null;
        $draftToken = $intent['draft_token'] ?? null;
        $targetId = isset($intent['target_id']) ? (int) $intent['target_id'] : null;
        $targetKey = $intent['target_key'] ?? null;

        if (($recordId === null) === ($draftToken === null)) {
            $this->invalidIntent();
        }

        if ($recordType === 'person' && ($targetId !== null || $targetKey !== null)) {
            $this->invalidIntent();
        }
        if ($recordType === 'case' && (($targetId === null) === ($targetKey === null))) {
            $this->invalidIntent();
        }

        if ($recordId !== null) {
            if ($recordType === 'person') {
                Person::query()->findOrFail($recordId);
            } else {
                InvestigationCase::query()->findOrFail($recordId);
                if ($targetId !== null) {
                    abort_unless(
                        CaseCategory::query()->whereKey($targetId)->where('case_file_id', $recordId)->exists(),
                        404,
                    );
                }
            }
        } elseif (! is_string($draftToken) || ! Str::isUuid($draftToken)) {
            $this->invalidIntent();
        }

        if ($targetKey !== null && (! is_string($targetKey) || ! Str::isUuid($targetKey))) {
            $this->invalidIntent();
        }

        return [
            'attachment_purpose' => $purpose,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'draft_token' => $draftToken,
            'target_id' => $targetId,
            'target_key' => $targetKey,
        ];
    }

    public function commitPersonUploads(
        User $user,
        Person $person,
        ?string $draftToken,
        ?string $mainImageToken,
        array $galleryTokens,
    ): void {
        if ($mainImageToken !== null) {
            $this->commitTransfer(
                $user,
                $mainImageToken,
                RecordAttachmentTypes::PERSON_MAIN_IMAGE,
                $person->getKey(),
                $draftToken,
                null,
                null,
                'main_image_upload_token',
            );
        }

        foreach ($galleryTokens as $token) {
            $this->commitTransfer(
                $user,
                $token,
                RecordAttachmentTypes::PERSON_GALLERY,
                $person->getKey(),
                $draftToken,
                null,
                null,
                'gallery_upload_tokens',
            );
        }
    }

    public function commitCaseUploads(
        User $user,
        InvestigationCase $caseFile,
        ?string $draftToken,
        array $attachments,
    ): void {
        foreach ($attachments as $attachment) {
            $this->commitTransfer(
                $user,
                $attachment['token'],
                RecordAttachmentTypes::CASE_EVIDENCE,
                $caseFile->getKey(),
                $draftToken,
                (int) $attachment['category_id'],
                $attachment['target_key'] ?? null,
                'categories',
            );
        }
    }

    public function attach(UploadTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $upload = UploadTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->with('file')
                ->firstOrFail();

            if ($upload->attached_at !== null || $upload->committed_at === null || ! $upload->file instanceof File) {
                return;
            }

            $file = $upload->file;
            $mediaType = $this->types->mediaType($file->category);

            if ($upload->attachment_purpose === RecordAttachmentTypes::PERSON_MAIN_IMAGE) {
                if ($mediaType !== 'image') {
                    throw ValidationException::withMessages([
                        'main_image_upload_token' => 'The primary profile image must be an image file.',
                    ]);
                }

                $person = Person::query()->lockForUpdate()->findOrFail($upload->record_id);
                $person->forceFill([
                    'main_image_path' => $file->stored_path,
                    'main_image_file_id' => $file->getKey(),
                ])->save();
            } elseif ($upload->attachment_purpose === RecordAttachmentTypes::PERSON_GALLERY) {
                $person = Person::query()->findOrFail($upload->record_id);
                PersonImage::query()->firstOrCreate(
                    ['file_id' => $file->getKey()],
                    [
                        'person_id' => $person->getKey(),
                        'path' => $file->stored_path,
                        'original_name' => $file->original_name,
                        'type' => $mediaType,
                        'poster_path' => $file->video_poster_path,
                        'playable_path' => $file->video_playable_path,
                        'duration' => $file->video_duration,
                    ],
                );
            } elseif ($upload->attachment_purpose === RecordAttachmentTypes::CASE_EVIDENCE) {
                $category = CaseCategory::query()
                    ->whereKey($upload->target_id)
                    ->where('case_file_id', $upload->record_id)
                    ->firstOrFail();
                CaseImage::query()->firstOrCreate(
                    ['file_id' => $file->getKey()],
                    [
                        'case_category_id' => $category->getKey(),
                        'path' => $file->stored_path,
                        'original_name' => $file->original_name,
                        'type' => $mediaType,
                        'poster_path' => $file->video_poster_path,
                        'playable_path' => $file->video_playable_path,
                        'duration' => $file->video_duration,
                    ],
                );
            } else {
                $this->invalidIntent();
            }

            $upload->forceFill(['attached_at' => now()])->save();
        });
    }

    private function commitTransfer(
        User $user,
        string $token,
        string $purpose,
        int $recordId,
        ?string $draftToken,
        ?int $targetId,
        ?string $targetKey,
        string $errorField,
    ): void {
        $upload = $user->uploadTransfers()->where('token', $token)->lockForUpdate()->first();
        if (! $upload instanceof UploadTransfer
            || $upload->attachment_purpose !== $purpose
            || $upload->record_type !== ($purpose === RecordAttachmentTypes::CASE_EVIDENCE ? 'case' : 'person')
            || ($upload->record_id !== null && $upload->record_id !== $recordId)
            || ($upload->record_id === null && (! is_string($draftToken) || $upload->draft_token !== $draftToken))
            || ($upload->target_id !== null && $upload->target_id !== $targetId)
            || ($upload->target_key !== null && $upload->target_key !== $targetKey)
            || in_array($upload->status, ['failed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                $errorField => 'One or more selected files are unavailable for this record. Retry or remove the failed upload, then save again.',
            ]);
        }

        if ($purpose === RecordAttachmentTypes::CASE_EVIDENCE) {
            abort_unless(
                CaseCategory::query()->whereKey($targetId)->where('case_file_id', $recordId)->exists(),
                404,
            );
        }

        if ($upload->committed_at !== null) {
            if ($upload->record_id !== $recordId || $upload->target_id !== $targetId) {
                throw ValidationException::withMessages([
                    $errorField => 'This upload has already been attached to a different record.',
                ]);
            }

            return;
        }

        $upload->forceFill([
            'record_id' => $recordId,
            'target_id' => $targetId,
            'target_key' => null,
            'committed_at' => now(),
        ])->save();

        if ($upload->status === 'done') {
            $this->attach($upload);
        } elseif ($upload->status === 'pending') {
            FinalizeUploadTransferJob::dispatch($upload->getKey())->afterCommit();
        }
    }

    private function invalidIntent(): never
    {
        throw ValidationException::withMessages([
            'attachment' => 'The upload attachment target is invalid or no longer available.',
        ]);
    }

    private function isConfiguredAdministrator(User $user): bool
    {
        $configuredAdminEmail = strtolower(trim((string) config('vault.admin.email')));

        return $configuredAdminEmail !== ''
            && strtolower($user->email) === $configuredAdminEmail
            && $user->department === null
            && in_array($user->account_status, ['active', null], true);
    }
}
