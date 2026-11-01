@extends($routePrefix === 'cfm.' ? 'layouts.department' : 'layouts.app')

@section('page_title', $caseFile->title.' Evidence Gallery — '.config('app.name'))
@if ($routePrefix === 'cfm.')
    @section('page_label', 'CASE EVIDENCE GALLERY')
@endif

@section($routePrefix === 'cfm.' ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> CASE EVIDENCE GALLERY <span class="eyebrow-index">{{ $caseFile->public_id }}</span></div>
            <h1>{{ $caseFile->title }} <span>gallery</span></h1>
            <p class="page-description">Manage categories and evidence for this case file.</p>
        </div>
        <div class="profile-heading-actions">
            <a class="button-secondary" href="{{ route($routePrefix.'cases.show', $caseFile) }}" wire:navigate>BACK TO CASE FILE</a>
        </div>
    </section>

    <section class="profile-gallery-page">
        <aside class="profile-gallery-category-panel">
            <div class="profile-section-heading">
                <div><span class="section-kicker">EVIDENCE ORGANISATION</span><h3>Categories</h3></div>
            </div>
            <nav class="profile-gallery-category-list" aria-label="Case evidence categories">
                @foreach ($categories as $category)
                    <a class="profile-gallery-category-link {{ $selectedCategory?->is($category) ? 'is-active' : '' }}" href="{{ route($routePrefix.'cases.gallery', ['caseFile' => $caseFile, 'category' => $category->id]) }}" wire:navigate>
                        <span>{{ $category->title }} @if($canManageGalleryAccess && $category->is_locked)<i class="ri ri-lock-line gallery-category-lock-indicator" title="Restricted category" aria-label="Restricted category"></i>@endif</span><span>{{ $category->assigned_images_count }}</span>
                    </a>
                @endforeach
            </nav>
            <form method="post" action="{{ route($routePrefix.'cases.categories.store', $caseFile) }}" class="profile-gallery-category-create" data-case-category-form>
                @csrf
                <label for="new-case-category-title">Add a category</label>
                <input id="new-case-category-title" name="title" type="text" maxlength="255" required placeholder="Category name">
                <label for="new-case-category-description">Description <span>(optional)</span></label>
                <textarea id="new-case-category-description" name="description" rows="3" maxlength="10000" placeholder="Add context for this category"></textarea>
                <button class="button-secondary" type="submit"><span data-action-label>CREATE CATEGORY</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            </form>
        </aside>

        <div class="profile-gallery-content">
            <div class="profile-section-heading">
                <div>
                    <span class="section-kicker">CASE {{ $caseFile->public_id }} / CATEGORY</span>
                    <h3>{{ $selectedCategory?->title ?? 'Evidence categories' }} <span>{{ $galleryMedia->total() }}</span></h3>
                </div>
            </div>

            @if ($selectedCategory && ! $categoryRestricted)
                <div class="profile-gallery-category-management case-gallery-category-management">
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
                                data-staff-url="{{ route('admin.gallery-access.cases.staff', ['caseFile' => $caseFile, 'category' => $selectedCategory]) }}"
                                data-lock-url="{{ route('admin.gallery-access.cases.lock', ['caseFile' => $caseFile, 'category' => $selectedCategory]) }}"
                                data-grant-url-template="{{ route('admin.gallery-access.cases.grant', ['caseFile' => $caseFile, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                data-revoke-url-template="{{ route('admin.gallery-access.cases.revoke', ['caseFile' => $caseFile, 'category' => $selectedCategory, 'user' => 'STAFF_ID']) }}"
                                aria-label="Access settings for {{ $selectedCategory->title }}"><i class="ri ri-settings-3-line" aria-hidden="true"></i></button>
                        @endif
                    </div>
                    <form method="post" action="{{ route($routePrefix.'cases.categories.destroy', ['caseFile' => $caseFile, 'category' => $selectedCategory]) }}" data-case-category-form data-confirm="Delete this evidence category? Its media will be retained in another category when possible." data-confirm-title="Delete evidence category" data-confirm-label="DELETE CATEGORY" data-confirm-destructive="true">
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
                <div class="case-evidence-gallery case-gallery-management-grid">
                    @foreach ($galleryMedia as $image)
                        @php($mediaNumber = $image->id)
                        @php($mediaLabel = $mediaNumber >= 1000000 ? rtrim(rtrim(number_format($mediaNumber / 1000000, 1, '.', ''), '0'), '.').'M' : ($mediaNumber >= 1000 ? rtrim(rtrim(number_format($mediaNumber / 1000, 1, '.', ''), '0'), '.').'K' : str_pad((string) $mediaNumber, 2, '0', STR_PAD_LEFT)))
                        <div class="case-evidence-entry">
                            @if ($image->type === 'video')
                                <button class="evidence-gallery-tile video-gallery-tile" type="button" data-video-viewer data-video-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" data-video-download-url="{{ route($routePrefix.'cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" data-video-title="{{ $image->original_name }}" aria-label="Play {{ $image->original_name }}">
                                    @if ($image->poster_path || $image->file?->video_poster_path)
                                        <img src="{{ route($routePrefix.'cases.images.poster', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="" loading="lazy" decoding="async" onerror="this.remove()">
                                    @else
                                        <video class="case-gallery-video-thumbnail" data-case-video-thumbnail data-thumbnail-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" muted playsinline preload="none" hidden aria-hidden="true"></video>
                                    @endif
                                    <span class="video-play-overlay" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                                </button>
                            @elseif (in_array($image->type, ['document', 'audio'], true))
                                @php($extension = strtoupper(pathinfo($image->original_name, PATHINFO_EXTENSION)) ?: 'FILE')
                                <a class="evidence-gallery-tile evidence-gallery-document" href="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" target="_blank" rel="noopener" aria-label="View {{ $image->original_name }}">
                                    <i class="ri ri-file-text-line evidence-gallery-document__watermark" aria-hidden="true"></i>
                                    <strong>{{ $extension }}</strong>
                                    <span title="{{ $image->original_name }}">{{ \Illuminate\Support\Str::limit(pathinfo($image->original_name, PATHINFO_FILENAME), 22, '...') }}</span>
                                </a>
                            @else
                                <button class="evidence-gallery-tile" type="button" data-image-viewer data-image-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" data-download-url="{{ route($routePrefix.'cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" data-image-title="{{ $image->original_name }}" data-image-extension="{{ pathinfo($image->original_name, PATHINFO_EXTENSION) }}" aria-label="View {{ $image->original_name }}">
                                    <img src="{{ route($routePrefix.'cases.images.thumbnail', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="{{ $image->original_name }}" loading="lazy" decoding="async">
                                </button>
                            @endif
                            <span class="profile-gallery-item-label" title="{{ $image->original_name }}">IMG {{ $mediaLabel }}</span>
                            <button class="gallery-change-category-button" type="button" data-open-category-picker data-category-dialog="[data-case-category-dialog]" data-category-action="{{ route($routePrefix.'cases.images.categories', ['caseFile' => $caseFile, 'image' => $image]) }}" data-category-ids="{{ json_encode($image->categories->modelKeys()) }}" aria-label="Change categories for {{ $image->original_name }}">CHANGE CATEGORY</button>
                        </div>
                    @endforeach
                </div>
                @if ($galleryMedia->hasPages())
                    {{ $galleryMedia->links() }}
                @endif
            @elseif ($categories->isEmpty())
                <div class="profile-gallery-empty">This case has no evidence categories yet. Add a category to start organising evidence.</div>
            @else
                <div class="profile-gallery-empty">This category has no media yet. Upload evidence through the case file editor, then assign it here.</div>
            @endif
        </div>
    </section>

    @if ($selectedCategory && ! $categoryRestricted)
        <dialog class="gallery-management-dialog" data-gallery-edit-dialog aria-labelledby="case-gallery-edit-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category editor">×</button></form>
            <form method="post" action="{{ route($routePrefix.'cases.categories.update', ['caseFile' => $caseFile, 'category' => $selectedCategory]) }}" data-case-category-form data-confirm="Save the name and description for this evidence category?" data-confirm-title="Update evidence category" data-confirm-label="SAVE CHANGES">
                @csrf
                @method('PUT')
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">CASE EVIDENCE ORGANISATION</span><h2 id="case-gallery-edit-title">Edit category</h2><p>Update the category name and its optional description.</p></div>
                    <button class="button-primary" type="submit"><span data-action-label>SAVE CHANGES</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
                </header>
                <label for="rename-case-category">Category name</label>
                <input id="rename-case-category" name="title" type="text" maxlength="255" required value="{{ $selectedCategory->title }}">
                <label for="describe-case-category">Description <span>(optional)</span></label>
                <textarea id="describe-case-category" name="description" rows="4" maxlength="10000" placeholder="Optional category description">{{ $selectedCategory->description }}</textarea>
                <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            </form>
        </dialog>
    @endif

    @if ($canManageGalleryAccess)
        <dialog class="gallery-management-dialog gallery-access-dialog" data-gallery-access-dialog aria-labelledby="case-gallery-access-title">
            <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close access settings">×</button></form>
            <div class="gallery-access-content">
                <header class="gallery-picker-header">
                    <div><span class="section-kicker">CATEGORY SECURITY</span><h2 id="case-gallery-access-title">Access settings</h2><p data-gallery-access-category-name></p></div>
                    <label class="gallery-lock-toggle"><input type="checkbox" data-gallery-lock-toggle><span class="gallery-lock-toggle-track"></span><span data-gallery-lock-label>Unlocked</span></label>
                </header>
                <div class="gallery-access-staff" data-gallery-access-staff-panel hidden>
                    <label for="case-gallery-access-search">Find Case File Management staff</label>
                    <input id="case-gallery-access-search" type="search" data-gallery-access-search placeholder="Search name or username" autocomplete="off">
                    <p class="gallery-access-helper">Only active staff from this department are listed. Grant or revoke access immediately.</p>
                    <div class="gallery-access-staff-list" data-gallery-access-staff-list aria-live="polite"></div>
                </div>
                <div class="gallery-access-unlocked-note" data-gallery-access-unlocked-note>While unlocked, this category follows the case file's existing access rules.</div>
                <p class="gallery-access-error" data-gallery-access-error role="alert" hidden></p>
            </div>
        </dialog>
    @endif

    <dialog class="gallery-category-picker" data-gallery-category-dialog data-case-category-dialog aria-labelledby="case-category-picker-title">
        <form method="dialog" class="gallery-picker-close"><button value="close" aria-label="Close category picker">×</button></form>
        <form method="post" action="#" data-case-category-form data-category-picker-form>
            @csrf
            @method('PUT')
            <header class="gallery-picker-header">
                <div><span class="section-kicker">CASE EVIDENCE ORGANISATION</span><h2 id="case-category-picker-title">Change categories</h2><p>Choose every category that should include this media.</p></div>
                <button class="button-primary" type="submit"><span data-action-label>SAVE CATEGORIES</span><span class="gallery-action-spinner" hidden aria-hidden="true"></span></button>
            </header>
            <p class="gallery-action-error" data-action-error role="alert" hidden></p>
            <div class="gallery-picker-list">
                @if ($categories->whereIn('id', $visibleCategoryIds)->isEmpty())
                    <p class="profile-gallery-empty">There are no categories available for assignment.</p>
                @else
                    @foreach ($categories as $category)
                        @if (in_array($category->id, $visibleCategoryIds, true))
                            <label class="gallery-picker-option">
                                <input type="checkbox" name="category_ids[]" value="{{ $category->id }}">
                                <span class="gallery-picker-check" aria-hidden="true"><i class="ri ri-check-line"></i></span>
                                <span><strong>{{ $category->title }}</strong><small>{{ $category->assigned_images_count }} media items</small></span>
                            </label>
                        @endif
                    @endforeach
                @endif
            </div>
        </form>
    </dialog>
    <x-video-viewer />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · {{ $caseFile->public_id }}</span><span>RESTRICTED · AUTHENTICATED ACCESS</span></footer>
@endsection
