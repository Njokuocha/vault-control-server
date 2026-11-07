<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaseAnalysisUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $caseId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('case-analysis.'.$this->caseId)];
    }

    public function broadcastAs(): string
    {
        return 'case.analysis.updated';
    }

    public function broadcastWith(): array
    {
        return ['case_id' => $this->caseId];
    }
}
