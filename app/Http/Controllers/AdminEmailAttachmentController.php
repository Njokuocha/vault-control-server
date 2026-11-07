<?php

namespace App\Http\Controllers;

use App\Models\SentEmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminEmailAttachmentController extends Controller
{
    public function download(SentEmailAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk('vault');
        abort_unless($disk->exists($attachment->stored_path), 404);

        return $disk->download($attachment->stored_path, $attachment->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
