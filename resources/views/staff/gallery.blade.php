@php($staffManagement = str_starts_with($routePrefix, 'sm.'))
@extends($staffManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', $staff->name.' Staff Gallery — '.config('app.name'))
@unless($staffManagement)
    @section('page_label', 'STAFF GALLERY')
@endunless

@section($staffManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE STAFF GALLERY <span class="eyebrow-index">{{ $staff->id }}</span></div>
            <h1>{{ $staff->name }} <span>gallery</span></h1>
            <p class="page-description">{{ $galleryTotalCount }} media items across {{ $categories->count() }} categories.</p>
        </div>
        <div class="profile-heading-actions">
            <a class="button-secondary" href="{{ route($routePrefix.'.show', ['user' => $staff, 'auth' => $authToken]) }}" wire:navigate>BACK TO STAFF PROFILE</a>
            <a class="button-primary" href="{{ route($galleryRoutePrefix.'.uploads', ['user' => $staff, 'auth' => $authToken]) }}" wire:navigate>UPLOAD MEDIA</a>
        </div>
    </section>

    <section class="profile-gallery-page staff-gallery-page" data-profile-gallery-page data-gallery-media-unlocked="true" data-media-auth-expires-at="{{ $mediaAuthExpiresAt }}">
        <aside class="profile-gallery-category-panel">
            <div class="profile-section-heading">
                <div><span class="section-kicker">STAFF ORGANISATION</span><h3>Categories</h3></div>
            </div>
            <nav class="profile-gallery-category-list" aria-label="Staff gallery categories">
                @foreach ($categories as $category)
                    <a class="profile-gallery-category-link {{ $selectedCategory?->is($category) ? 'is-active' : '' }}" href="{{ route($galleryRoutePrefix.'.index', ['user' => $staff, 'category' => $category->id, 'auth' => $authToken]) }}" wire:navigate>
                        <span>{{ $category->title }} @if($canManageGalleryAccess && $category->is_locked)<i class="ri ri-lock-line gallery-category-lock-indicator" title="Restricted category" aria-label="Restricted category"></i>@endif</span><span>{{ $category->images_count }}</span>
                    </a>
                @endforeach
                <a class="profile-gallery-category-link {{ $selectedUncategorized ? 'is-active' : '' }}" href="{{ route($galleryRoutePrefix.'.index', ['user' => $staff, 'category' => 'uncategorized', 'auth' => $authToken]) }}" wire:navigate>
                    <span>Uncategorized</span><span>{{ $uncategorizedCount }}</span>
                </a>
            </nav>
            <form method="post" action="{{ route($galleryRoutePrefix.'.categories.store', ['user' => $staff, 'auth' => $authToken]) }}" class="profile-gallery-category-create" data-gallery-action-form>
                @csrf
                <label for="staff-gallery-category-title">Add a category</label>
                <input id="staff-gallery-category-title" name="title" type="text" maxlength="255" required value="{{ old('title') }}" placeholder="Category name">
                <label for="staff-gallery-category-description">Description <span>(optional)</span></label>
                <textarea id="staff-gallery-category-description" name="description" rows="3" maxlength="10000" placeholder="Add context for this category">{{ old('description') }}</textarea>
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
                                data-staff-url="{{ route('admin.gallery-access.staff-gallery.staff', ['staff' => $staff, 'category' => $selectedCategory]) }}"
                                data-lock-url="{{ route('admin.gallery-access.staff-gallery.lock', ['staff' => $staff, 'category' => $selectedCategory]) }}"
                                data-grant-url-template="{{ route('admin.gallery-access.staff-gallery.grant', ['staff' => $staff, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                data-revoke-url-template="{{ route('admin.gallery-access.staff-gallery.revoke', ['staff' => $staff, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                aria-label="Access settings for {{ $selectedCategory->title }}"><i class="ri ri-settings-3-line" aria-hidden="true"></i></button>
                        @endif
                    </div>
                    <form method="post" action="{{ route($galleryRoutePrefix.'.categories.destroy', ['user' => $staff, 'category' => $selectedCategory, 'auth' => $authToken]) }}" data-gallery-action-form data-confirm="Delete this category? Its media will remain in the gallery as uncategorized." data-confirm-title="Delete gallery category" data-confirm-label="DELETE CATEGORY" data-confirm-destructive="true">
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
                <div class="profile-gallery" data-profile-gallery>
                    @foreach ($galleryMedia as $image)
                        @php($mediaNumber = $image->id)
                        @php($mediaLabel = $mediaNumber >= 1000000 ? rtrim(rtrim(number_format($mediaNumber / 1000000, 1, '.', ''), '0'), '.').'M' : ($mediaNumber >= 1000 ? rtrim(rtrim(number_format($mediaNumber / 1000, 1, '.', ''), '0'), '.').'K' : str_pad((string) $mediaNumber, 2, '0', STR_PAD_LEFT)))
                        <div class="profile-gallery-entry">
                            @if ($image->type === 'video')
                                <button class="profile-gallery-tile video-gallery-tile" type="button" data-video-viewer data-video-url="{{ route($galleryRoutePrefix.'.image', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" data-video-download-url="{{ route($galleryRoutePrefix.'.images.download', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" data-video-title="IMG {{ $mediaLabel }}" aria-label="Play video {{ $mediaLabel }}">
                                    @if ($image->file?->video_poster_path)
                                        <img data-protected-image data-image-url="{{ route($galleryRoutePrefix.'.images.poster', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" alt="" loading="lazy" hidden>
                                    @else
                                        <video class="profile-gallery-video-thumbnail" data-protected-video-thumbnail data-thumbnail-url="{{ route($galleryRoutePrefix.'.image', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" muted playsinline preload="none" hidden aria-hidden="true"></video>
                                    @endif
                                    <span class="video-play-overlay" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                                </button>
                            @else
                                <button class="profile-gallery-tile" type="button" data-image-viewer data-image-url="{{ route($galleryRoutePrefix.'.image', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" data-download-url="{{ route($galleryRoutePrefix.'.images.download', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" data-image-title="IMG {{ $mediaLabel }}" aria-label="View image {{ $mediaLabel }}">
                                    <img data-protected-image data-image-url="{{ route($galleryRoutePrefix.'.image', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" data-thumbnail-url="{{ route($galleryRoutePrefix.'.images.thumbnail', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken]) }}" alt="{{ $image->original_name }}" loading="lazy" hidden>
                                </button>
                            @endif
                            <span class="profile-gallery-item-label">IMG {{ $mediaLabel }}</span>
                            <button class="gallery-change-category-button" type="button" data-open-category-picker data-category-dialog="[data-gallery-category-dialog]" data-category-action="{{ route($galleryRoutePrefix.'.images.categories', ['user' => $staff, 'image' => $image->id, 'auth' => $authToken, 'category' => request()->query('category')]) }}" data-category-ids="{{ json_encode($image->categories->modelKeys()) }}" aria-label="Change categories for IMG {{ $mediaLabel }}">CHANGE CATEGORY</button>
                        </div>
                    @endforeach
                </div>
                @if ($galleryMedia->hasPages())
                    {{ $galleryMedia->links() }}
                @endif
            @elseif ($categories->isEmpty() && $uncategorizedCount === 0)
                <div class="profile-gallery-empty">This staff gallery has no categories or media yet. Create a category above or upload media to get started.</div>
            @elseif ($selectedUncategorized && $uncategorizedCount === 0)
                <div class="profile-gallery-empty">There is no uncategorized media for this staff profile.</div>
            @else
                <div class="profile-gallery-empty">This category has no media yet. Upload media or assign existing items using the category selector.</div>
            @endif
        </div>
    </section>

    @if ($selectedCategory && ! $categoryRestricted)
        <dialog class="gallery-management-dialog" data-gallery-edit-dialog aria-labelledby="staff-gallery-edit-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category editor">×</button></form>
            <form method="post" action="{{ route($galleryRoutePrefix.'.categories.update', ['user' => $staff, 'category' => $selectedCategory, 'auth' => $authToken]) }}" data-gallery-action-form data-confirm="Save the name and description for this gallery category?" data-confirm-title="Update gallery category" data-confirm-label="SAVE CHANGES">
                @csrf
                @method('PUT')
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">STAFF ORGANISATION</span><h2 id="staff-gallery-edit-title">Edit category</h2><p>Update the category name and its optional description.</p></div>
                    <button class="button-primary" type="submit"><span data-action-label>SAVE CHANGES</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                </header>
                <label for="rename-staff-gallery-category">Category name</label>
                <input id="rename-staff-gallery-category" name="title" type="text" maxlength="255" required value="{{ $selectedCategory->title }}">
                <label for="describe-staff-gallery-category">Description <span>(optional)</span></label>
                <textarea id="describe-staff-gallery-category" name="description" rows="4" maxlength="10000" placeholder="Optional category description">{{ $selectedCategory->description }}</textarea>
                <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            </form>
        </dialog>
    @endif

    @if ($canManageGalleryAccess)
        <dialog class="gallery-management-dialog gallery-access-dialog" data-gallery-access-dialog aria-labelledby="staff-gallery-access-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close access settings">×</button></form>
            <div class="gallery-access-content">
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">CATEGORY SECURITY</span><h2 id="staff-gallery-access-title">Access settings</h2><p data-gallery-access-category-name></p></div>
                    <label class="gallery-lock-toggle"><input type="checkbox" data-gallery-lock-toggle><span class="gallery-lock-toggle-track"></span><span data-gallery-lock-label>Unlocked</span></label>
                </header>
                <div class="gallery-access-staff" data-gallery-access-staff-panel hidden>
                    <label for="staff-gallery-access-search">Find Staff Management staff</label>
                    <input id="staff-gallery-access-search" type="search" data-gallery-access-search placeholder="Search name or username" autocomplete="off">
                    <p class="gallery-access-helper">Only active Staff Management accounts are listed. Grant or revoke access immediately.</p>
                    <div class="gallery-access-staff-list" data-gallery-access-staff-list aria-live="polite"></div>
                </div>
                <div class="gallery-access-unlocked-note" data-gallery-access-unlocked-note>While unlocked, this category follows the profile's existing access rules.</div>
                <p class="gallery-access-error" data-gallery-access-error role="alert" hidden></p>
            </div>
        </dialog>
    @endif

    <dialog class="gallery-category-picker" data-gallery-category-dialog aria-labelledby="staff-gallery-category-picker-title">
        <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category picker">×</button></form>
        <form method="post" action="#" data-gallery-action-form data-category-picker-form>
            @csrf
            @method('PUT')
            <header class="gallery-picker-header">
                <div><span class="section-kicker">STAFF ORGANISATION</span><h2 id="staff-gallery-category-picker-title">Change categories</h2><p>Select each category that should include this media.</p></div>
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

    <x-video-viewer />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · STAFF GALLERY</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
