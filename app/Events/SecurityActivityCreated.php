<?php

namespace App\Events;

use App\Models\ActivityLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityActivityCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ActivityLog $activityLog)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.security')];
    }

    public function broadcastAs(): string
    {
        return 'security.activity.created';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->activityLog->id,
            'category' => $this->activityLog->category,
            'action' => $this->activityLog->action,
            'actor_name' => $this->activityLog->actor_name,
            'summary' => $this->activityLog->summary,
            'occurred_at' => $this->activityLog->occurred_at?->toIso8601String(),
        ];
    }
}
