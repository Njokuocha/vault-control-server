<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\BruteForceRecord;
use Illuminate\Contracts\View\View;

class AuthSecurityController extends Controller
{
    public function authEvent(AuthEvent $authEvent): View
    {
        return view('admin.security.auth-event', ['event' => $authEvent]);
    }

    public function activityLog(ActivityLog $activityLog): View
    {
        return view('admin.security.activity-detail', ['log' => $activityLog]);
    }

    public function bruteForce(BruteForceRecord $bruteForceRecord): View
    {
        return view('admin.security.brute-force-detail', ['record' => $bruteForceRecord]);
    }
}
