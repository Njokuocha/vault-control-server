<?php

namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\View\View;

class ManualUploadController extends Controller
{
    public function create(SettingService $settings): View
    {
        return view('uploads.create', [
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

}
