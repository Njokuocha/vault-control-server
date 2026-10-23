<?php

namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(SettingService $settings): View
    {
        return view('settings.index', [
            'uploadKey' => $settings->get('upload_key'),
            'uploadEndpoint' => url('/api/upload'),
        ]);
    }

    public function updateUploadKey(Request $request, SettingService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'upload_key' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $settings->set('upload_key', $validated['upload_key']);

        return back()->with('status', 'Upload key updated. The previous key is no longer valid.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();
        $user->password = Hash::make($validated['password']);
        $user->save();

        $request->session()->regenerate();

        return back()->with('status', 'Administrator password updated.');
    }
}
