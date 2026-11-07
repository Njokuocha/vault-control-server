<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileDeleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $category,
        public readonly string $monthKey,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.vault'), new PrivateChannel('fm.vault')];
    }

    public function broadcastAs(): string
    {
        return 'file.deleted';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'category' => $this->category,
            'month_key' => $this->monthKey,
            'status' => 'deleted',
        ];
    }
}
