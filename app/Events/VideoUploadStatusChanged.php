<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoUploadStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly string $status,
        public readonly int $progress,
        public readonly ?string $fileUuid = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.vault')];
    }

    public function broadcastAs(): string
    {
        return 'video.upload.status';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'token' => $this->token,
            'status' => $this->status,
            'progress' => $this->progress,
            'file_uuid' => $this->fileUuid,
        ];
    }
}
