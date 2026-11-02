<?php

namespace App\Livewire\Admin;

use App\Services\StaffManagementMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class StaffAnalyticsDashboard extends Component
{
    public function render(): View
    {
        return view('livewire.admin.staff-analytics-dashboard', [
            'metrics' => app(StaffManagementMetrics::class)->analytics(),
            'routePrefix' => 'admin',
        ])->extends('layouts.app', [
            'page_title' => 'Staff Analytics — '.config('app.name'),
            'page_label' => 'STAFF ANALYTICS',
        ])->section('content');
    }
}
