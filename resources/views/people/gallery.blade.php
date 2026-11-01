@extends($profileManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', $person->name . ' Gallery — ' . config('app.name'))
@unless($profileManagement)
    @section('page_label', 'PROFILE GALLERY')
@endunless

@section($profileManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE PROFILE GALLERY <span class="eyebrow-index">{{ $person->public_id }}</span></div>
            <h1>{{ $person->name }} <span>gallery</span></h1>
            <p class="page-description">{{ $galleryTotalCount }} media items across {{ $categories->count() }} categories.</p>
        </div>
        <div class="profile-heading-actions">
            <button class="button-secondary" type="button" data-open-image-pin>UNLOCK VIEWABLES</button>
            <a class="button-secondary" href="{{ route($routePrefix.'.show', $person) }}" wire:navigate>BACK TO PROFILE</a>
        </div>
    </section>

    <section class="profile-gallery-page">
        <aside class="profile-gallery-category-panel">
            <div class="profile-section-heading">
                <div><span class="section-kicker">PROFILE ORGANISATION</span><h3>Categories</h3></div>
            </div>
            <nav class="profile-gallery-category-list" aria-label="Gallery categories">
                @foreach ($categories as $category)
                    <a class="profile-gallery-category-link {{ $selectedCategory?->is($category) ? 'is-active' : '' }}" href="{{ route($routePrefix.'.gallery', ['person' => $person, 'category' => $category->id]) }}" wire:navigate>
                        <span>{{ $category->title }} @if($canManageGalleryAccess && $category->is_locked)<i class="ri ri-lock-line gallery-category-lock-indicator" title="Restricted category" aria-label="Restricted category"></i>@endif</span><span>{{ $category->images_count }}</span>
                    </a>
                @endforeach
                <a class="profile-gallery-category-link {{ $selectedUncategorized ? 'is-active' : '' }}" href="{{ route($routePrefix.'.gallery', ['person' => $person, 'category' => 'uncategorized']) }}" wire:navigate>
                    <span>Uncategorized</span><span>{{ $uncategorizedCount }}</span>
                </a>
            </nav>
            <form method="post" action="{{ route($routePrefix.'.gallery-categories.store', $person) }}" class="profile-gallery-category-create" data-gallery-action-form>
                @csrf
                <label for="gallery-category-title">Add a category</label>
                <input id="gallery-category-title" name="title" type="text" maxlength="255" required value="{{ old('title') }}" placeholder="Category name">
                <label for="gallery-category-description">Description <span>(optional)</span></label>
                <textarea id="gallery-category-description" name="description" rows="3" maxlength="10000" placeholder="Add context for this category">{{ old('description') }}</textarea>
                @error('title')<p class="form-error">{{ $message }}</p>@enderror
                <button class="button-secondary" type="submit"><span data-action-label>CREATE CATEGORY</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            </form>
        </aside>

        <div class="profile-gallery-content">
            <div class="profile-section-heading">
                <div>
                    <span class="section-kicker">BROWSING {{ $selectedUncategorized ? 'UNCATEGORIZED' : 'CATEGORY' }}</span>
                    <h3>{{ $selectedCategory?->title ?? 'Uncategorized media' }} <span>{{ $galleryMedia->total() }}</span></h3>
                </div>
            </div>

            @if ($selectedCategory && ! $categoryRestricted)
                <div class="profile-gallery-category-management">
                    <div class="gallery-category-management-copy">
                        <div><span class="section-kicker">CATEGORY SETTINGS</span><strong>{{ $selectedCategory->title }}</strong></div>
                        @if (filled($selectedCategory->description))
                            <p class="gallery-category-description">{{ $selectedCategory->description }}</p>
                        @endif
                    </div>
                    <div class="gallery-category-management-actions">
                        <button class="button-secondary" type="button" data-open-gallery-edit aria-label="Edit {{ $selectedCategory->title }}">EDIT CATEGORY</button>
                        @if ($canManageGalleryAccess)
                            <button class="gallery-category-settings-button" type="button" data-gallery-access-open
                                data-category-name="{{ $selectedCategory->title }}"
                                data-category-locked="{{ $selectedCategory->is_locked ? 'true' : 'false' }}"
                                data-staff-url="{{ route('admin.gallery-access.people.staff', ['person' => $person, 'category' => $selectedCategory]) }}"
                                data-lock-url="{{ route('admin.gallery-access.people.lock', ['person' => $person, 'category' => $selectedCategory]) }}"
                                data-grant-url-template="{{ route('admin.gallery-access.people.grant', ['person' => $person, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                data-revoke-url-template="{{ route('admin.gallery-access.people.revoke', ['person' => $person, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                aria-label="Access settings for {{ $selectedCategory->title }}"><i class="ri ri-settings-3-line" aria-hidden="true"></i></button>
                        @endif
                    </div>
                    <form method="post" action="{{ route($routePrefix.'.gallery-categories.destroy', ['person' => $person, 'category' => $selectedCategory->id]) }}" data-gallery-action-form data-confirm="Delete this category? Its media will be kept as uncategorized." data-confirm-title="Delete gallery category" data-confirm-label="DELETE CATEGORY" data-confirm-destructive="true">
                        @csrf
                        @method('DELETE')
                        <button class="button-danger" type="submit"><span data-action-label>DELETE CATEGORY</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                        <p class="gallery-action-error" data-action-error role="alert" hidden></p>
                    </form>
                </div>
            @endif

            @if ($categoryRestricted)
                <div class="gallery-category-restricted" role="status">
                    <i class="ri ri-lock-line" aria-hidden="true"></i>
                    <strong>Restricted category</strong>
                    <span>This category is locked. Contact an administrator if you need access.</span>
                </div>
            @elseif ($galleryMedia->isNotEmpty())
                <div class="profile-gallery is-locked" data-profile-gallery>
                    @foreach ($galleryMedia as $image)
                        @php($mediaNumber = $image->id)
                        @php($mediaLabel = $mediaNumber >= 1000000 ? rtrim(rtrim(number_format($mediaNumber / 1000000, 1, '.', ''), '0'), '.').'M' : ($mediaNumber >= 1000 ? rtrim(rtrim(number_format($mediaNumber / 1000, 1, '.', ''), '0'), '.').'K' : str_pad((string) $mediaNumber, 2, '0', STR_PAD_LEFT)))
                        <div class="profile-gallery-entry">
                        @if ($image->type === 'video')
                            <button class="profile-gallery-tile video-gallery-tile" type="button" data-video-viewer data-video-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" data-video-download-url="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => $image->id]) }}" data-video-title="IMG {{ $mediaLabel }}" data-video-pin-required aria-label="Locked video {{ $mediaLabel }}. Unlock viewables to preview.">
                                <span class="image-lock-placeholder" data-image-lock-placeholder>?</span>
                                @if ($image->poster_path || $image->file?->video_poster_path)
                                    <img data-protected-image data-image-url="{{ route($routePrefix.'.images.poster', ['person' => $person, 'image' => $image->id]) }}" alt="" loading="lazy" hidden>
                                @else
                                    <video class="profile-gallery-video-thumbnail" data-protected-video-thumbnail data-thumbnail-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" muted playsinline preload="none" hidden aria-hidden="true"></video>
                                @endif
                                <span class="video-play-overlay" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                            </button>
                        @elseif (in_array($image->type, [null, 'image'], true))
                            <button class="profile-gallery-tile" type="button" data-image-viewer data-image-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" data-download-url="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => $image->id]) }}" data-image-title="IMG {{ $mediaLabel }}" aria-label="Locked image {{ $mediaLabel }}. Unlock viewables to preview.">
                                <span class="image-lock-placeholder" data-image-lock-placeholder>?</span>
                                <img data-protected-image data-image-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" data-thumbnail-url="{{ route($routePrefix.'.images.thumbnail', ['person' => $person, 'image' => $image->id]) }}" alt="{{ $image->original_name }}" loading="lazy" hidden>
                            </button>
                        @else
                            <a class="profile-gallery-tile profile-gallery-file-tile" href="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => $image->id]) }}">
                                <span class="image-lock-placeholder" aria-hidden="true">?</span>
                                <strong>IMG {{ $mediaLabel }}</strong>
                                <small>Unlock viewables to download</small>
                            </a>
                        @endif
                        <span class="profile-gallery-item-label">IMG {{ $mediaLabel }}</span>
                        <button class="gallery-change-category-button" type="button" data-open-category-picker data-category-dialog="[data-gallery-category-dialog]" data-category-action="{{ route($routePrefix.'.gallery-images.category', ['person' => $person, 'image' => $image->id]) }}" data-category-ids="{{ $image->galleryCategories->modelKeys() ? json_encode($image->galleryCategories->modelKeys()) : '[]' }}" aria-label="Change categories for IMG {{ $mediaLabel }}">CHANGE CATEGORY</button>
                        </div>
                    @endforeach
                </div>
                @if ($galleryMedia->hasPages())
                    {{ $galleryMedia->links() }}
                @endif
            @elseif ($categories->isEmpty() && $uncategorizedCount === 0)
                <div class="profile-gallery-empty">This profile has no gallery categories or media yet. Create a category above to get started.</div>
            @elseif ($selectedUncategorized && $uncategorizedCount === 0)
                <div class="profile-gallery-empty">There is no uncategorized media for this profile.</div>
            @else
                <div class="profile-gallery-empty">This category has no media yet. Assign existing media here using the selector on another category, including Uncategorized.</div>
            @endif
        </div>
    </section>

    @if ($selectedCategory && ! $categoryRestricted)
        <dialog class="gallery-management-dialog" data-gallery-edit-dialog aria-labelledby="gallery-edit-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category editor">×</button></form>
            <form method="post" action="{{ route($routePrefix.'.gallery-categories.update', ['person' => $person, 'category' => $selectedCategory]) }}" data-gallery-action-form data-confirm="Save the name and description for this gallery category?" data-confirm-title="Update gallery category" data-confirm-label="SAVE CHANGES">
                @csrf
                @method('PUT')
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">PROFILE ORGANISATION</span><h2 id="gallery-edit-title">Edit category</h2><p>Update the category name and its optional description.</p></div>
                    <button class="button-primary" type="submit"><span data-action-label>SAVE CHANGES</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                </header>
                <label for="rename-gallery-category">Category name</label>
                <input id="rename-gallery-category" name="title" type="text" maxlength="255" required value="{{ $selectedCategory->title }}">
                <label for="describe-gallery-category">Description <span>(optional)</span></label>
                <textarea id="describe-gallery-category" name="description" rows="4" maxlength="10000" placeholder="Optional category description">{{ $selectedCategory->description }}</textarea>
                <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            </form>
        </dialog>
    @endif

    @if ($canManageGalleryAccess)
        <dialog class="gallery-management-dialog gallery-access-dialog" data-gallery-access-dialog aria-labelledby="gallery-access-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close access settings">×</button></form>
            <div class="gallery-access-content">
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">CATEGORY SECURITY</span><h2 id="gallery-access-title">Access settings</h2><p data-gallery-access-category-name></p></div>
                    <label class="gallery-lock-toggle"><input type="checkbox" data-gallery-lock-toggle><span class="gallery-lock-toggle-track"></span><span data-gallery-lock-label>Unlocked</span></label>
                </header>
                <div class="gallery-access-staff" data-gallery-access-staff-panel hidden>
                    <label for="gallery-access-search">Find Profile Management staff</label>
                    <input id="gallery-access-search" type="search" data-gallery-access-search placeholder="Search name or username" autocomplete="off">
                    <p class="gallery-access-helper">Only active staff from this department are listed. Grant or revoke access immediately.</p>
                    <div class="gallery-access-staff-list" data-gallery-access-staff-list aria-live="polite"></div>
                </div>
                <div class="gallery-access-unlocked-note" data-gallery-access-unlocked-note>While unlocked, this category follows the profile's existing access rules.</div>
                <p class="gallery-access-error" data-gallery-access-error role="alert" hidden></p>
            </div>
        </dialog>
    @endif

    <dialog class="gallery-category-picker" data-gallery-category-dialog aria-labelledby="gallery-category-picker-title">
        <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category picker">×</button></form>
        <form method="post" action="#" data-gallery-action-form data-category-picker-form>
            @csrf
            @method('PUT')
            <header class="gallery-picker-header">
                <div><span class="section-kicker">PROFILE ORGANISATION</span><h2 id="gallery-category-picker-title">Change categories</h2><p>Select every category that should include this media.</p></div>
                <button class="button-primary" type="submit"><span data-action-label>SAVE CATEGORIES</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
            </header>
            <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            <div class="gallery-picker-list">
                @if ($categories->whereIn('id', $visibleCategoryIds)->isEmpty())
                    <p class="profile-gallery-empty">Create a category before assigning media.</p>
                @else
                    @foreach ($categories as $category)
                        @if (in_array($category->id, $visibleCategoryIds, true))
                            <label class="gallery-picker-option">
                                <input type="checkbox" name="category_ids[]" value="{{ $category->id }}">
                                <span class="gallery-picker-check" aria-hidden="true"><i class="ri ri-check-line"></i></span>
                                <span><strong>{{ $category->title }}</strong><small>{{ $category->images_count }} media items</small></span>
                            </label>
                        @endif
                    @endforeach
                @endif
            </div>
            <p class="gallery-picker-hint">Leave every category unchecked to make this item uncategorized.</p>
        </form>
    </dialog>

    <dialog class="image-pin-dialog" data-image-pin-dialog data-unlock-url="{{ route($routePrefix.'.images.unlock', $person) }}" data-pin-configured="{{ $imagePinConfigured ? 'true' : 'false' }}">
        <form method="dialog" class="image-pin-close-form"><button value="close" aria-label="Close PIN window">×</button></form>
        <div class="image-pin-content">
            <span class="pin-security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5.5c0 4.4-2.8 7.4-7 9.5-4.2-2.1-7-5.1-7-9.5V6z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/></svg></span>
            <span class="section-kicker">RESTRICTED IMAGE ACCESS</span>
            <h2>Unlock viewables</h2>
            <p>Enter the four-digit image PIN to view this profile's private gallery.</p>
            @if ($imagePinConfigured)
                <form method="post" action="{{ route($routePrefix.'.images.unlock', $person) }}" data-image-pin-form>
                    @csrf
                    <div class="pin-digit-row" data-pin-digit-row>
                        @for ($digit = 0; $digit < 4; $digit++)
                            <input type="password" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="{{ $digit === 0 ? 'one-time-code' : 'off' }}" aria-label="PIN digit {{ $digit + 1 }}" data-pin-digit>
                        @endfor
                    </div>
                    <p class="pin-error" data-pin-error role="alert" aria-live="polite"></p>
                    <button type="submit" class="button-primary pin-submit" data-submit-image-pin>AUTHENTICATE PIN</button>
                </form>
            @else
                <div class="pin-setup-required">An administrator must configure the image PIN in Settings before images can be viewed.</div>
                @unless($profileManagement)
                    <a class="button-primary pin-submit" href="{{ route('settings.index') }}">OPEN SECURITY SETTINGS</a>
                @endunless
            @endif
        </div>
    </dialog>
    <x-video-viewer />
@endsection
