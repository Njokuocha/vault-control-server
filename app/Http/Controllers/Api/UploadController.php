<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function store(Request $request, FileUploadService $uploads): JsonResponse
    {
        $uploads->assertUploadsAllowed();

        $validated = $request->validate([
            'file' => ['sometimes', 'file'],
            'files' => ['sometimes', 'array', 'min:1', 'max:20'],
            'files.*' => ['file'],
        ]);

        $files = collect($validated['files'] ?? []);

        if (isset($validated['file'])) {
            $files->prepend($validated['file']);
        }

        if ($files->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'No file provided.',
            ]);
        }

        if ($files->count() > 20) {
            throw ValidationException::withMessages([
                'files' => 'A maximum of 20 files may be uploaded in one request.',
            ]);
        }

        $files->each(fn ($file) => $uploads->assertWithinLimit($file));

        $stored = $files->map(fn ($file): File => $uploads->store($file, $request->ip(), 'api'));

        return response()->json([
            'status' => 'success',
            'data' => $stored->map(fn (File $file): array => [
                'uuid' => $file->uuid,
                'name' => $file->original_name,
                'size' => $file->size,
                'mime' => $file->mime_type,
                'upload_method' => $file->upload_method,
                'uploaded_at' => $file->created_at?->toIso8601String(),
            ])->values(),
        ], 201);
    }
}
