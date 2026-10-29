<?php

namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\View\View;

class ManualUploadController extends Controller
{
    public function create(SettingService $settings): View
    {
        return view(request()->routeIs('fm.*') ? 'departments.fm.upload' : 'uploads.create', [
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

}
