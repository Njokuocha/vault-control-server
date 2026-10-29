<?php

namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvatarModelController extends Controller
{
    private const GROUPS = ['male', 'female', 'group', 'unknown', 'suspect', 'verified'];

    public function index(SettingService $settings): View
    {
        $models = [];
        foreach (self::GROUPS as $group) {
            $path = $settings->get('avatar_model_'.$group);
            $models[$group] = is_string($path) && Storage::disk(config('vault.upload_disk'))->exists($path)
                ? route('avatar-models.stream', $group)
                : null;
        }

        return view('avatar-models.index', ['models' => $models, 'groups' => self::GROUPS]);
    }

    public function update(Request $request, string $group, SettingService $settings): RedirectResponse
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);
        $validated = $request->validate([
            'model' => ['required', 'file', 'max:51200'],
        ]);
        $model = $validated['model'];

        if (strtolower($model->getClientOriginalExtension()) !== 'glb' || ! $this->isSafeGlb($model->getRealPath())) {
            return back()->withErrors(['model' => 'Select a valid binary glTF 2.0 (.glb) with embedded assets only.']);
        }

        $path = $model->storeAs('avatar-models', $group.'-'.Str::uuid().'.glb', config('vault.upload_disk'));
        if (! is_string($path)) {
            throw new \RuntimeException('Unable to save the 3D avatar model.');
        }

        $oldPath = $settings->get('avatar_model_'.$group);
        $settings->set('avatar_model_'.$group, $path);
        if (is_string($oldPath) && $oldPath !== '') {
            Storage::disk(config('vault.upload_disk'))->delete($oldPath);
        }

        return back()->with('status', ucfirst($group).' avatar model updated.');
    }

    public function stream(string $group, SettingService $settings): mixed
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);
        $path = $settings->get('avatar_model_'.$group);
        abort_unless(is_string($path) && Storage::disk(config('vault.upload_disk'))->exists($path), 404);

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $path,
            'model/gltf-binary',
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    private function isSafeGlb(?string $path): bool
    {
        if (! is_string($path) || ! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 12);
            if (! is_string($header) || strlen($header) !== 12) {
                return false;
            }
            $parts = unpack('Vmagic/Vversion/Vlength', $header);
            if ($parts['magic'] !== 0x46546C67 || $parts['version'] !== 2 || $parts['length'] !== filesize($path)) {
                return false;
            }

            $chunkHeader = fread($handle, 8);
            if (! is_string($chunkHeader) || strlen($chunkHeader) !== 8) {
                return false;
            }
            $chunk = unpack('Vlength/Vtype', $chunkHeader);
            if ($chunk['type'] !== 0x4E4F534A || $chunk['length'] > 5 * 1024 * 1024) {
                return false;
            }

            $json = fread($handle, $chunk['length']);
            if (! is_string($json) || strlen($json) !== $chunk['length']) {
                return false;
            }
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

            return is_array($document) && $this->hasNoExternalUris($document);
        } catch (\JsonException) {
            return false;
        } finally {
            fclose($handle);
        }
    }

    private function hasNoExternalUris(array $document): bool
    {
        foreach ($document as $key => $value) {
            if (strtolower((string) $key) === 'uri' && is_string($value) && ! str_starts_with($value, 'data:')) {
                return false;
            }
            if (is_array($value)) {
                if (! $this->hasNoExternalUris($value)) {
                    return false;
                }
            } elseif (is_string($value) && preg_match('/^https?:|^\\/\\//i', $value)) {
                return false;
            }
        }

        return true;
    }
}
