<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\User;
use App\Services\ReportAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ReportMediaController extends Controller
{
    public function __invoke(Report $report, ReportMedia $media, ReportAccess $access): Response
    {
        abort_unless($media->report_id === $report->id && $access->canView($report), 404);
        abort_unless(
            in_array($media->media_type, ['image', 'video'], true)
                && str_starts_with($media->path, 'reports/'.$report->id.'/'),
            404,
        );

        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'",
        ], 'inline');
    }

    public function commenterPhoto(User $user, ReportAccess $access): Response
    {
        $access->actor();
        abort_unless($user->department !== null, 404);

        $path = $user->profile_photo_path;
        abort_unless(is_string($path) && str_starts_with($path, 'staff-profiles/'.$user->id.'/'), 404);

        $disk = Storage::disk('vault');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
