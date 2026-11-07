<?php

namespace App\Http\Middleware;

use App\Models\Person;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpirePersonImagePinOutsideGallery
{
    public function handle(Request $request, Closure $next): Response
    {
        $unlockedPersonId = $request->session()->get('unlocked_person_images');
        $unlockedGalleryPersonId = $request->session()->get('unlocked_person_gallery');

        if (($unlockedPersonId === null && $unlockedGalleryPersonId === null) || ! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->routeIs('people.gallery', '*.people.gallery')) {
            $person = $request->route('person');
            $personId = $person instanceof Person ? $person->getKey() : $person;

            if (
                (string) $personId === (string) $unlockedPersonId
                || (string) $personId === (string) $unlockedGalleryPersonId
            ) {
                return $next($request);
            }
        } elseif ($request->routeIs(
            'people.image',
            '*.people.image',
            'people.images.*',
            '*.people.images.*',
            'fm.person-media.*',
            'admin.gallery-access.people.staff',
        )) {
            return $next($request);
        }

        $request->session()->forget(['unlocked_person_images', 'unlocked_person_gallery']);

        return $next($request);
    }
}
