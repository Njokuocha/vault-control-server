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
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'apiUploadsEnabled' => $settings->apiUploadsEnabled(),
            'imagePinConfigured' => $settings->get('person_image_pin_hash') !== null,
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

    public function updateUploadPolicy(Request $request, SettingService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'max_upload_mb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'api_upload_enabled' => ['required', 'boolean'],
        ]);

        $settings->set('max_upload_mb', (string) $validated['max_upload_mb']);
        $settings->set('api_upload_enabled', $request->boolean('api_upload_enabled') ? '1' : '0');

        return back()->with('status', 'Upload policy updated.');
    }

    public function updateImagePin(Request $request, SettingService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'image_pin' => ['required', 'digits:4', 'confirmed'],
        ]);

        $settings->set('person_image_pin_hash', Hash::make($validated['image_pin']));
        $request->session()->forget('unlocked_person_images');

        return back()->with('status', 'Person image access PIN updated.');
    }
}
