<?php

namespace App\Livewire\StaffManager;

use App\Models\User;
use App\Services\StaffManagementMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class StaffManagerDashboard extends Component
{
    public function render(): View
    {
        $staffManager = Auth::guard('web')->user();
        abort_unless(
            $staffManager instanceof User
                && $staffManager->department === 'sm'
                && $staffManager->account_status === 'active',
            403,
        );

        return view('livewire.staff-manager.dashboard', [
            'metrics' => app(StaffManagementMetrics::class)->overview(),
            'routePrefix' => 'sm',
        ])->extends('layouts.department', [
            'page_title' => 'Staff Management — '.config('app.name'),
        ])->section('department_content');
    }
}
