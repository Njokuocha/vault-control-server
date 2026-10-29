<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StaffProfilePhotoController extends Controller
{
    public function department(Request $request): Response
    {
        $staff = $request->user();
        abort_unless($staff instanceof User, 404);

        return $this->photoResponse($staff);
    }

    public function admin(User $user): Response
    {
        abort_unless($user->department !== null, 404);

        return $this->photoResponse($user);
    }

    private function photoResponse(User $staff): Response
    {
        $path = $staff->profile_photo_path;
        abort_unless(
            is_string($path) && str_starts_with($path, 'staff-profiles/'.$staff->id.'/'),
            404
        );

        abort_unless(Storage::disk('vault')->exists($path), 404);

        return app(FileController::class)->streamPrivatePath(request(), $path);
    }
}
