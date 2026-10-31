<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceQueryListener
{
    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        DB::listen(static function (QueryExecuted $query): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = app('request');
            if (! $request instanceof Request) {
                return;
            }

            $metrics = $request->attributes->get(RequestPerformanceMetrics::REQUEST_ATTRIBUTE);
            if ($metrics instanceof RequestPerformanceMetrics) {
                $metrics->record($query);
            }
        });

        $this->registered = true;
    }
}
