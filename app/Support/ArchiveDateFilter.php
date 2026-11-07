<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ArchiveDateFilter
{
    public static function apply(Builder $query, string $year, string $month): void
    {
        if ($year !== '') {
            $start = Carbon::createFromDate((int) $year, $month === '' ? 1 : (int) $month, 1)->startOfDay();
            $end = $month === '' ? $start->copy()->addYear() : $start->copy()->addMonth();

            $query->where('created_at', '>=', $start)
                ->where('created_at', '<', $end);

            return;
        }

        if ($month !== '') {
            $query->whereMonth('created_at', (int) $month);
        }
    }
}
