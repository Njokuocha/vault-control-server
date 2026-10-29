<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityMetricsUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $activeAccounts,
        public readonly string $capturedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.security')];
    }

    public function broadcastAs(): string
    {
        return 'security.metrics.updated';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'active_accounts' => $this->activeAccounts,
            'captured_at' => $this->capturedAt,
        ];
    }
}
