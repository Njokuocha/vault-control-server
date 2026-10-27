<?php

namespace App\Services;

use App\Events\UploadTransferStatusChanged;
use App\Models\UploadTransfer;

class UploadTransferStatus
{
    public function broadcast(UploadTransfer $upload, ?string $status = null): void
    {
        $query = UploadTransfer::query()->where('user_id', $upload->user_id);
        $totalUploads = (clone $query)->count();
        $pendingUploads = (clone $query)->whereIn('status', ['uploading', 'pending', 'processing'])->count();

        event(new UploadTransferStatusChanged(
            userId: $upload->user_id,
            uploadId: $upload->getKey(),
            name: $upload->original_name,
            size: $upload->total_size,
            status: $status ?? $upload->status,
            totalUploads: $totalUploads,
            pendingUploads: $pendingUploads,
            fileUuid: $upload->file?->uuid,
        ));
    }
}
