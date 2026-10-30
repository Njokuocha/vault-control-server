<?php

namespace App\Services;

use App\Events\UploadTransferStatusChanged;
use App\Models\Admin;
use App\Models\UploadTransfer;
use App\Models\User;
use RuntimeException;

class UploadTransferStatus
{
    public function broadcast(UploadTransfer $upload, ?string $status = null): void
    {
        $owner = $upload->admin ?? $upload->user;
        if (! $owner instanceof Admin && ! $owner instanceof User) {
            throw new RuntimeException('Upload transfer status requires a valid owner.');
        }

        $query = UploadTransfer::query()->ownedBy($owner);
        $totalUploads = (clone $query)->count();
        $pendingUploads = (clone $query)->whereIn('status', ['uploading', 'pending', 'processing'])->count();

        event(new UploadTransferStatusChanged(
            userId: $owner instanceof User ? $owner->getKey() : null,
            uploadId: $upload->getKey(),
            name: $upload->original_name,
            size: $upload->total_size,
            status: $status ?? $upload->status,
            totalUploads: $totalUploads,
            pendingUploads: $pendingUploads,
            fileUuid: $upload->file?->uuid,
            adminId: $owner instanceof Admin ? $owner->getKey() : null,
        ));
    }
}
