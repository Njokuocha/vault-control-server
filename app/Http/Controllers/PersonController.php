<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\ActivityLogger;
use App\Services\PublicRecordId;
use App\Services\RecordUploadAttachmentService;
use App\Services\ReportAccess;
use App\Services\SettingService;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PersonController extends Controller
{
    private const TEXT_FIELDS = [
        'name', 'first_name', 'last_name', 'surname', 'age', 'date_of_birth',
        'marital_status', 'spouse', 'gender', 'citizenship', 'state',
        'blood_group', 'genotype', 'height', 'occupation', 'aliases',
        'last_known_location', 'identifying_marks', 'statement',
    ];

    public function index(): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.people-index');
        }

        return view('people.index');
    }

    public function create(): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.form', [
                'person' => new Person,
            ]);
        }

        return view('people.form', [
            'person' => new Person,
        ]);
    }

    public function store(Request $request, PublicRecordId $ids, RecordUploadAttachmentService $attachments): RedirectResponse|JsonResponse
    {
        $validated = $this->validatePerson($request);
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $mainImage = $request->file('main_image');
        $otherImages = $request->file('other_images', []);
        $storedPaths = [];

        try {
            $person = DB::transaction(function () use ($validated, $mainImage, $otherImages, $ids, $attachments, $actor, $request, &$storedPaths): Person {
                $attributes = $this->personAttributes($validated);
                $attributes['public_id'] = $ids->generate(Person::class, 'USR');
                $attributes['avatar_group'] = $validated['avatar_group'] ?? $this->avatarGroup($attributes['gender']);

                if ($mainImage instanceof UploadedFile) {
                    $attributes['main_image_path'] = $this->storeImage($mainImage, 'people/main', $storedPaths);
                    $attributes['main_image_file_id'] = null;
                }

                $person = Person::query()->create($attributes);

                foreach ($otherImages as $image) {
                    if ($image instanceof UploadedFile) {
                        $person->images()->create([
                            'path' => $this->storeImage($image, 'people/'.$person->id.'/gallery', $storedPaths),
                            'original_name' => $this->safeImageName($image),
                        ]);
                    }
                }
                $this->attachVideosToPerson($person, $validated['video_upload_tokens'] ?? []);
                $attachments->commitPersonUploads(
                    $actor,
                    $person,
                    $validated['record_upload_draft_token'] ?? null,
                    $validated['main_image_upload_token'] ?? null,
                    $validated['gallery_upload_tokens'] ?? [],
                );
                app(ActivityLogger::class)->record(
                    'profile-management',
                    'person.created',
                    "Person profile {$person->public_id} was created.",
                    [
                        'person_id' => $person->getKey(),
                        'public_id' => $person->public_id,
                        'gallery_items_submitted' => count($otherImages) + count($validated['gallery_upload_tokens'] ?? []),
                        'has_primary_image' => $mainImage instanceof UploadedFile || ! empty($validated['main_image_upload_token']),
                    ],
                    $actor,
                    $request,
                );

                return $person;
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        $redirectUrl = route($this->routePrefix().'.show', $person);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Person profile created.',
                'record_id' => $person->getKey(),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Person profile created.');
    }

    public function show(Person $person, SettingService $settings, ReportAccess $reportAccess): Response
    {
        request()->session()->forget('unlocked_person_images');
        $profileManagement = request()->routeIs('pm.*');
        $person = app(VersionedCache::class)->remember(
            'people',
            ($profileManagement ? 'pm:' : '').'profile:'.$person->getKey(),
            now()->addSeconds(30),
            fn (): Person => $profileManagement
                ? $person->load('images.file')
                : $person->load(['images.file', 'cases' => fn ($query) => $query->latest()]),
        );
        $person->loadMissing('images.file');
        $linkedReports = $reportAccess->visibleQuery()
            ->whereHas('people', fn ($query) => $query->whereKey($person->id))
            ->withCount('comments')
            ->latest('updated_at')
            ->get();
        $avatarGroup = strtolower($person->avatar_group);
        $avatarPath = in_array($avatarGroup, ['male', 'female', 'group', 'unknown', 'suspect', 'verified'], true)
            ? $settings->get('avatar_model_'.$avatarGroup)
            : null;

        return response()->view('people.show', [
            'person' => $person,
            'linkedReports' => $linkedReports,
            'profileManagement' => $profileManagement,
            'routePrefix' => $this->routePrefix(),
            'imagePinConfigured' => $settings->get('person_image_pin_hash') !== null,
            'avatarModelUrl' => is_string($avatarPath) && Storage::disk(config('vault.upload_disk'))->exists($avatarPath)
                ? route($profileManagement ? 'pm.avatar-models.stream' : 'avatar-models.stream', $avatarGroup)
                : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function edit(Person $person): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.form', [
                'person' => $person,
            ]);
        }

        return view('people.form', [
            'person' => $person,
        ]);
    }

    public function update(Request $request, Person $person, RecordUploadAttachmentService $attachments): RedirectResponse|JsonResponse
    {
        $validated = $this->validatePerson($request);
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $mainImage = $request->file('main_image');
        $otherImages = $request->file('other_images', []);
        $storedPaths = [];
        $previousMainImage = $person->main_image_path;
        $removeMainImage = (bool) ($validated['remove_main_image'] ?? false);

        try {
            DB::transaction(function () use ($validated, $mainImage, $otherImages, $person, $removeMainImage, $attachments, $actor, $request, &$storedPaths): void {
                $attributes = $this->personAttributes($validated);
                $attributes['avatar_group'] = $validated['avatar_group'] ?? $this->avatarGroup($attributes['gender']);

                if ($mainImage instanceof UploadedFile) {
                    $attributes['main_image_path'] = $this->storeImage($mainImage, 'people/main', $storedPaths);
                    $attributes['main_image_file_id'] = null;
                } elseif ($removeMainImage) {
                    $attributes['main_image_path'] = null;
                    $attributes['main_image_file_id'] = null;
                }

                $person->update($attributes);

                foreach ($otherImages as $image) {
                    if ($image instanceof UploadedFile) {
                        $person->images()->create([
                            'path' => $this->storeImage($image, 'people/'.$person->id.'/gallery', $storedPaths),
                            'original_name' => $this->safeImageName($image),
                        ]);
                    }
                }
                $this->attachVideosToPerson($person, $validated['video_upload_tokens'] ?? []);
                $attachments->commitPersonUploads(
                    $actor,
                    $person,
                    $validated['record_upload_draft_token'] ?? null,
                    $validated['main_image_upload_token'] ?? null,
                    $validated['gallery_upload_tokens'] ?? [],
                );
                app(ActivityLogger::class)->record(
                    'profile-management',
                    'person.updated',
                    "Person profile {$person->public_id} was updated.",
                    [
                        'person_id' => $person->getKey(),
                        'public_id' => $person->public_id,
                        'changed_fields' => array_keys($person->getChanges()),
                        'gallery_items_submitted' => count($otherImages) + count($validated['gallery_upload_tokens'] ?? []),
                        'has_primary_image' => $mainImage instanceof UploadedFile || ! empty($validated['main_image_upload_token']),
                    ],
                    $actor,
                    $request,
                );
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        if (($mainImage instanceof UploadedFile || $removeMainImage) && $previousMainImage) {
            Storage::disk(config('vault.upload_disk'))->delete($previousMainImage);
        }

        $redirectUrl = route($this->routePrefix().'.show', $person);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Person profile updated.',
                'record_id' => $person->getKey(),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Person profile updated.');
    }

    public function authenticateImages(Request $request, Person $person, SettingService $settings): JsonResponse
    {
        $validated = $request->validate(['pin' => ['required', 'digits:4']]);
        $pinHash = $settings->get('person_image_pin_hash');

        if (! is_string($pinHash) || ! Hash::check($validated['pin'], $pinHash)) {
            return response()->json([
                'message' => is_string($pinHash) ? 'The PIN is incorrect.' : 'No person image PIN has been configured.',
            ], 422);
        }

        $request->session()->put('unlocked_person_images', $person->getKey());

        return response()->json(['status' => 'unlocked']);
    }

    public function image(Person $person, string $image, bool $download = false): mixed
    {
        abort_unless(request()->session()->get('unlocked_person_images') === $person->getKey(), 403);

        if ($image !== 'main') {
            $media = $person->images()->whereKey($image)->firstOrFail();
            if ($media->type === 'video') {
                $file = $media->file;
                abort_unless($file instanceof File, 404);

                return $download
                    ? app(FileController::class)->download($file)
                    : ($media->playable_path
                        ? app(FileController::class)->videoRendition($file)
                        : app(FileController::class)->stream(request(), $file));
            }
            if (in_array($media->type, ['audio', 'document'], true)) {
                $file = $media->file;
                abort_unless($file instanceof File, 404);

                return app(FileController::class)->download($file);
            }
            $path = $media->path;
        } else {
            $file = $person->mainImageFile;
            if ($file instanceof File) {
                abort_unless($file->category === 'image', 404);

                return $download
                    ? app(FileController::class)->download($file)
                    : app(FileController::class)->stream(request(), $file);
            }
            $path = $person->main_image_path;
        }

        abort_unless(is_string($path) && Storage::disk(config('vault.upload_disk'))->exists($path), 404);

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $path,
            null,
            basename($path),
            $download,
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function imagePoster(Person $person, PersonImage $image): mixed
    {
        abort_unless(request()->session()->get('unlocked_person_images') === $person->getKey(), 403);
        abort_unless($image->person_id === $person->id && $image->type === 'video', 404);
        $disk = Storage::disk(config('vault.upload_disk'));
        $posterPath = collect([$image->poster_path, $image->file?->video_poster_path])
            ->first(fn ($path): bool => is_string($path) && $disk->exists($path));
        abort_unless(is_string($posterPath), 404);

        $fileName = pathinfo($image->original_name, PATHINFO_FILENAME).'-poster.jpg';

        return app(FileController::class)->streamPrivatePath(request(), $posterPath, 'image/jpeg', $fileName);
    }

    public function destroyImage(Person $person, PersonImage $image): JsonResponse
    {
        abort_unless($image->person_id === $person->id, 404);
        $file = $image->file;
        $videoUploadId = $file
            ? VideoUpload::query()->where('file_id', $file->id)->value('id')
            : null;
        Storage::disk(config('vault.upload_disk'))->delete($image->path);
        $image->delete();
        if ($file) {
            $file->delete();
            if ($videoUploadId !== null) {
                VideoUpload::query()->whereKey($videoUploadId)->delete();
            }
        }

        return response()->json(['status' => 'deleted']);
    }

    public function linkableCases(Request $request, Person $person): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $cases = InvestigationCase::query()
            ->whereDoesntHave('people', fn ($query) => $query->whereKey($person->id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('public_id', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%')
                        ->orWhere('classification', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->limit(20)
            ->get(['id', 'public_id', 'title', 'classification', 'case_status']);

        return response()->json(['cases' => $cases]);
    }

    public function attachCase(Request $request, Person $person): JsonResponse
    {
        $validated = $request->validate([
            'case_id' => ['required', 'integer', 'exists:case_files,id'],
        ]);
        $person->cases()->syncWithoutDetaching([$validated['case_id']]);
        app(VersionedCache::class)->bump('people');
        app(VersionedCache::class)->bump('cases');

        return response()->json(['status' => 'linked']);
    }

    public function detachCase(Person $person, InvestigationCase $case): JsonResponse
    {
        $person->cases()->detach($case->id);
        app(VersionedCache::class)->bump('people');
        app(VersionedCache::class)->bump('cases');

        return response()->json(['status' => 'unlinked']);
    }

    private function validatePerson(Request $request): array
    {
        $rules = [];
        foreach (self::TEXT_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.($field === 'statement' || $field === 'identifying_marks' ? 20000 : 255)];
        }

        return $request->validate([
            ...$rules,
            'flag' => ['nullable', Rule::in(['Alive', 'Deceased', 'Wanted', 'Unknown'])],
            'avatar_group' => ['nullable', Rule::in(['Male', 'Female', 'Group', 'Unknown', 'Suspect', 'Verified'])],
            'main_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'other_images' => ['nullable', 'array', 'max:40'],
            'other_images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'video_upload_tokens' => ['nullable', 'array', 'max:20'],
            'video_upload_tokens.*' => ['required', 'uuid', 'distinct', 'exists:video_uploads,token'],
            'record_upload_draft_token' => ['nullable', 'uuid'],
            'main_image_upload_token' => ['nullable', 'uuid'],
            'gallery_upload_tokens' => ['nullable', 'array', 'max:40'],
            'gallery_upload_tokens.*' => ['required', 'uuid', 'distinct'],
            'remove_main_image' => ['nullable', 'boolean'],
        ]);
    }

    private function routePrefix(): string
    {
        return request()->routeIs('pm.*') ? 'pm.people' : 'people';
    }

    private function attachVideosToPerson(Person $person, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $uploads = VideoUpload::query()
            ->whereIn('token', $tokens)
            ->where('status', 'completed')
            ->whereNull('attached_at')
            ->with('file')
            ->lockForUpdate()
            ->get();

        if ($uploads->count() !== count($tokens) || $uploads->contains(
            fn (VideoUpload $upload): bool => ! $upload->file || $upload->file->category !== 'video'
        )) {
            throw ValidationException::withMessages([
                'video_upload_tokens' => 'One or more selected videos are not available to attach.',
            ]);
        }

        foreach ($uploads as $upload) {
            $person->images()->create([
                'path' => $upload->file->stored_path,
                'original_name' => $upload->file->original_name,
                'type' => 'video',
                'file_id' => $upload->file_id,
                'poster_path' => $upload->file->video_poster_path,
                'playable_path' => $upload->file->video_playable_path,
                'duration' => $upload->file->video_duration,
            ]);
            $upload->forceFill(['attached_at' => now()])->save();
        }
    }

    private function personAttributes(array $validated): array
    {
        $attributes = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = trim((string) ($validated[$field] ?? ''));
            $attributes[$field] = $value === '' ? 'Unknown' : $value;
        }

        $attributes['flag'] = $validated['flag'] ?? 'Unknown';

        if ($attributes['name'] === 'Unknown') {
            $nameParts = array_filter([
                $attributes['first_name'],
                $attributes['last_name'],
                $attributes['surname'],
            ], fn (string $value): bool => $value !== 'Unknown');
            $attributes['name'] = $nameParts === [] ? 'Unknown' : implode(' ', $nameParts);
        }

        return $attributes;
    }

    private function avatarGroup(string $gender): string
    {
        return match (strtolower($gender)) {
            'male' => 'Male',
            'female' => 'Female',
            'group' => 'Group',
            default => 'Unknown',
        };
    }

    private function storeImage(UploadedFile $image, string $directory, array &$storedPaths): string
    {
        $extension = strtolower($image->guessExtension() ?: 'img');
        $path = $image->storeAs($directory, Str::uuid().'.'.$extension, config('vault.upload_disk'));
        if (! is_string($path)) {
            throw new \RuntimeException('Unable to store the uploaded image.');
        }

        $storedPaths[] = $path;

        return $path;
    }

    private function safeImageName(UploadedFile $image): string
    {
        return Str::limit(basename(str_replace('\\', '/', $image->getClientOriginalName())), 255, '');
    }
}
