<?php

namespace App\Http\Controllers;

use App\Services\VaultShellService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VaultShellController extends Controller
{
    public function index(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['sometimes', 'string', 'max:1024'],
        ]);

        return response()->json($shell->list($validated['path'] ?? '/root'));
    }

    public function preview(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json($shell->preview($validated['path']));
    }

    public function stream(Request $request, VaultShellService $shell): BinaryFileResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
        ]);

        $absolutePath = $shell->stream($validated['path']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        if (in_array($mime, ['text/html', 'image/svg+xml', 'application/xml', 'text/xml'], true)) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'";
        }

        return response()->file($absolutePath, $headers);
    }

    public function download(Request $request, VaultShellService $shell): BinaryFileResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
        ]);

        return response()->download($shell->stream($validated['path']));
    }

    public function destroy(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'confirmation' => ['required', 'in:yes'],
        ]);

        return response()->json([
            'deleted' => $shell->delete($validated['path']),
        ]);
    }

    public function makeDirectory(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json([
            'created' => $shell->makeDirectory($validated['path']),
        ], 201);
    }

    public function copy(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:1024'],
            'destination' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json([
            'copied' => $shell->copy($validated['source'], $validated['destination']),
        ], 201);
    }

    public function move(Request $request, VaultShellService $shell): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:1024'],
            'destination' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json([
            'moved' => $shell->move($validated['source'], $validated['destination']),
        ]);
    }
}
