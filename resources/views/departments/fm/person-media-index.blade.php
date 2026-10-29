@extends('layouts.department')

@section('page_title', 'Person media — '.config('app.name'))
@section('department_content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PROTECTED MEDIA INDEX <span class="eyebrow-index">VIEWABLES ONLY</span></div>
            <h1>Person <span>media</span></h1>
            <p class="page-description">Browse image and video collections without access to profile or case information.</p>
        </div>
    </section>
    <section class="cfm-recent-cases" aria-label="Person media collections">
        <header><div><span class="section-kicker">MEDIA COLLECTIONS</span><h2>Protected viewables</h2></div><span class="text-action">{{ number_format($mediaGroups->total()) }} COLLECTIONS</span></header>
        @forelse ($mediaGroups as $mediaGroup)
            <a class="cfm-recent-case" href="{{ route('fm.person-media.show', $mediaGroup->id) }}" wire:navigate>
                <span class="cfm-recent-case__mark ri ri-image-line" aria-hidden="true"></span>
                <span class="cfm-recent-case__details"><strong>MEDIA COLLECTION {{ str_pad((string) $mediaGroup->id, 4, '0', STR_PAD_LEFT) }}</strong><small>{{ number_format($mediaGroup->media_count + (int) (bool) ($mediaGroup->main_image_path || $mediaGroup->main_image_file_id)) }} protected item(s)</small></span>
                <span class="case-index-card-creator" aria-hidden="true">↗</span>
            </a>
        @empty
            <p class="linked-case-empty">No person image or video media is currently available.</p>
        @endforelse
        @if ($mediaGroups->hasPages())
            <div class="records-pagination">{{ $mediaGroups->links() }}</div>
        @endif
    </section>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · PROTECTED MEDIA</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
