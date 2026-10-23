<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManualUploadController extends Controller
{
    public function create(SettingService $settings): View
    {
        return view('uploads.create', [
            'maxUploadMb' => $settings->maxUploadMegabytes(),
        ]);
    }

    public function store(Request $request, FileUploadService $uploads): RedirectResponse
    {
        $uploads->assertUploadsAllowed();

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file'],
        ]);

        collect($validated['files'])->each(fn ($file) => $uploads->assertWithinLimit($file));

        $stored = collect($validated['files'])
            ->map(fn ($file) => $uploads->store($file, $request->ip()));

        return back()->with(
            'status',
            $stored->count().' file'.($stored->count() === 1 ? '' : 's').' uploaded successfully.',
        );
    }
}
