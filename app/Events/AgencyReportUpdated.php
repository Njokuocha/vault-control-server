<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

class AgencyReportUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $reportId,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('reports.agency')];
    }

    public function broadcastAs(): string
    {
        return 'agency.report.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'report_id' => $this->reportId,
            'action' => $this->action,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
