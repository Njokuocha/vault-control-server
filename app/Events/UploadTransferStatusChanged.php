<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UploadTransferStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $userId,
        public readonly int $uploadId,
        public readonly string $name,
        public readonly int $size,
        public readonly string $status,
        public readonly int $totalUploads,
        public readonly int $pendingUploads,
        public readonly ?string $fileUuid = null,
        public readonly ?int $adminId = null,
    ) {}

    public function broadcastOn(): array
    {
        $ownerId = $this->adminId ?? $this->userId;
        if ($ownerId === null) {
            throw new \LogicException('Upload transfer updates require an authenticated owner.');
        }

        return [new PrivateChannel($this->adminId === null
            ? 'uploads.'.$ownerId
            : 'admin.uploads.'.$ownerId)];
    }

    public function broadcastAs(): string
    {
        return 'upload.transfer.status-changed';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'name' => $this->name,
            'size' => $this->size,
            'status' => $this->status,
            'total_uploads' => $this->totalUploads,
            'pending_uploads' => $this->pendingUploads,
            'file_uuid' => $this->fileUuid,
        ];
    }
}
