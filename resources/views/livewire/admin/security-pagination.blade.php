@if ($paginator->hasPages())
    @php
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $firstVisiblePage = max(1, $currentPage - 2);
        $lastVisiblePage = min($lastPage, $currentPage + 2);
        $pageName = $paginator->getPageName();
    @endphp
    <nav class="security-dashboard-pagination__nav" role="navigation" aria-label="Recent activity pagination">
        <span class="security-dashboard-pagination__summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
        <div class="security-dashboard-pagination__controls">
            <button type="button" wire:click="previousPage('{{ $pageName }}')" @disabled($paginator->onFirstPage()) aria-label="Previous page">PREV</button>
            @if ($firstVisiblePage > 1)
                <button type="button" wire:click="gotoPage(1, '{{ $pageName }}')">1</button>
                @if ($firstVisiblePage > 2)<span aria-hidden="true">…</span>@endif
            @endif
            @for ($page = $firstVisiblePage; $page <= $lastVisiblePage; $page++)
                <button
                    type="button"
                    wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                    @if ($page === $currentPage) aria-current="page" @endif
                >{{ $page }}</button>
            @endfor
            @if ($lastVisiblePage < $lastPage)
                @if ($lastVisiblePage < $lastPage - 1)<span aria-hidden="true">…</span>@endif
                <button type="button" wire:click="gotoPage({{ $lastPage }}, '{{ $pageName }}')">{{ $lastPage }}</button>
            @endif
            <button type="button" wire:click="nextPage('{{ $pageName }}')" @disabled(!$paginator->hasMorePages()) aria-label="Next page">NEXT</button>
        </div>
    </nav>
@endif
