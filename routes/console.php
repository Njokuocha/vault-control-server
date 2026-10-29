<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vault:prune-video-uploads')->hourly()->withoutOverlapping();
Schedule::command('vault:prune-upload-transfers')->hourly()->withoutOverlapping();
Schedule::command('security:snapshot-sessions')
    ->everyMinute()
    ->withoutOverlapping()
    ->createMutexNameUsing('framework/schedule-security-snapshot-v2');
Schedule::command('security:prune-records')->daily()->withoutOverlapping();
