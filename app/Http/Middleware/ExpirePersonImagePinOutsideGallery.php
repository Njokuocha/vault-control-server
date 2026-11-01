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

        if ($unlockedPersonId === null || ! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->routeIs('people.gallery', '*.people.gallery')) {
            $person = $request->route('person');
            $personId = $person instanceof Person ? $person->getKey() : $person;

            if ((string) $personId === (string) $unlockedPersonId) {
                return $next($request);
            }
        } elseif ($request->routeIs(
            'people.image',
            '*.people.image',
            'people.images.*',
            '*.people.images.*',
            'admin.gallery-access.people.staff',
        )) {
            return $next($request);
        }

        $request->session()->forget('unlocked_person_images');

        return $next($request);
    }
}
