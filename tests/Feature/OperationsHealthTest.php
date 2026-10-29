<?php

use App\Events\AgencyReportUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Artisan;

test('agency report events are queued for private after-commit broadcasting', function () {
    $event = new AgencyReportUpdated(42, 'updated');
    $channels = $event->broadcastOn();

    expect($event)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-reports.agency');
});

test('all required scheduler tasks are registered with Laravel', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)
        ->toContain('vault:prune-video-uploads')
        ->toContain('vault:prune-upload-transfers')
        ->toContain('security:snapshot-sessions')
        ->toContain('security:prune-records');
});
