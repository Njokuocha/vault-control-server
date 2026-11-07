<a class="case-linked-report-card" href="{{ route($reportIndexRoute, ['report' => $report->uuid]) }}" wire:navigate>
    @php($media = $report->media->first())
    <span class="case-linked-report-card__thumbnail">
        @if ($media && ($media->media_type === 'image' || $media->thumbnail_path !== null))
            <img src="{{ route('reports.media.thumbnail', [$report, $media]) }}" alt="" loading="lazy" decoding="async">
        @elseif ($media?->media_type === 'video')
            <i class="ri ri-play-fill" aria-hidden="true"></i>
        @else
            <i class="ri ri-file-text-line" aria-hidden="true"></i>
        @endif
        @if ($media?->media_type === 'video' && $media->thumbnail_path !== null)
            <i class="ri ri-play-fill case-linked-report-card__play" aria-hidden="true"></i>
        @endif
    </span>
    <span class="case-linked-report-card__copy">
        <strong>{{ \Illuminate\Support\Str::limit(app(\App\Support\ReportBodyFormatter::class)->plainText($report->body), 140) }}</strong>
        <small>{{ $report->created_at?->format('M d, Y · H:i') }} UTC · {{ $report->comments_count }} comments</small>
    </span>
    <i class="ri ri-arrow-right-up-line case-linked-report-card__arrow" aria-hidden="true"></i>
</a>
