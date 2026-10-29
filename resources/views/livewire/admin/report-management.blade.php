<div class="content-inner report-system-page">
    <header class="report-system-heading">
        <div><span class="section-kicker">CENTRAL ADMINISTRATION · MODERATION</span><h1>Report Control</h1><p>Manage internal visibility and retention of agency reports.</p></div>
        <a class="button-secondary" href="{{ route('admin.reports.index') }}" wire:navigate>← REPORT SYSTEM</a>
    </header>

    <section class="glass-panel report-management-panel">
        <div class="report-management-toolbar">
            <div><span class="section-kicker">REPORT REGISTER</span><h2>{{ $reports->total() }} Records</h2></div>
            <label><span>SEARCH REPORTS</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference, narrative, or reporter"></label>
        </div>
        <div class="security-table-wrap">
            <table class="security-table report-management-table">
                <thead><tr><th>REPORT TOKEN / NARRATIVE</th><th>REPORTER</th><th>LINKED RECORDS</th><th>COMMENTS / MEDIA</th><th>UPDATED</th><th>VISIBILITY</th><th>ACTIONS</th></tr></thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr wire:key="report-management-{{ $report->id }}">
                            <td><a href="{{ route('admin.reports.index', ['report' => $report->uuid]) }}" wire:navigate><code>{{ strtoupper(substr(str_replace('-', '', $report->uuid), 0, 12)) }}</code><span>{{ \Illuminate\Support\Str::limit($report->body, 90) }}</span></a></td>
                            <td>{{ $report->reporter?->name ?? 'Former account' }}<small>{{ $report->reporter instanceof \App\Models\User ? strtoupper($report->reporter->departmentRecord?->rank ?? 'AGENCY STAFF') : 'ADMINISTRATOR' }}</small></td>
                            <td><span>{{ $report->cases_count }} CASE</span><span>{{ $report->people_count }} PERSON</span></td>
                            <td>{{ $report->comments_count }} COMMENTS<br>{{ $report->media_count }} MEDIA</td>
                            <td>{{ $report->updated_at?->format('M d, Y · H:i') }}</td>
                            <td><span class="report-control-visibility {{ $report->is_hidden ? 'is-hidden' : '' }}">{{ $report->is_hidden ? 'HIDDEN' : 'INTERNAL' }}</span></td>
                            <td>
                                <div class="report-control-actions">
                                    <button class="report-control-icon" type="button" wire:click="toggleHidden({{ $report->id }})" wire:loading.attr="disabled" wire:target="toggleHidden({{ $report->id }})" aria-label="{{ $report->is_hidden ? 'Show report' : 'Hide report' }}" title="{{ $report->is_hidden ? 'Show report' : 'Hide report' }}">
                                        <span wire:loading.remove wire:target="toggleHidden({{ $report->id }})" class="ri {{ $report->is_hidden ? 'ri-eye-off-line' : 'ri-eye-line' }}" aria-hidden="true"></span>
                                        <span wire:loading wire:target="toggleHidden({{ $report->id }})" class="ri ri-loader-4-line is-spinning" aria-hidden="true"></span>
                                    </button>
                                    <button class="report-control-icon is-danger" type="button" wire:click="requestDelete({{ $report->id }})" aria-label="Delete report" title="Delete report"><span class="ri ri-delete-bin-line" aria-hidden="true"></span></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="security-table-empty" colspan="7">No reports match this search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="security-pagination">{{ $reports->links() }}</div>
    </section>

    @if ($confirmingReport)
        @php($hasConnections = $confirmingReport->cases_count > 0 || $confirmingReport->people_count > 0)
        <div class="report-modal-backdrop" role="presentation" wire:click.self="cancelDelete">
            <section class="report-delete-modal" role="alertdialog" aria-modal="true" aria-labelledby="report-delete-title">
                <span class="section-kicker">RETENTION CONTROL</span>
                <h2 id="report-delete-title">{{ $hasConnections ? 'Report is linked' : 'Delete this report?' }}</h2>
                @if ($hasConnections)
                    <p>This report is connected to {{ $confirmingReport->cases_count }} case file(s) and {{ $confirmingReport->people_count }} person record(s). It cannot be deleted while these links exist.</p>
                @else
                    <p>This permanently removes the report and its {{ $confirmingReport->comments_count }} comment(s) and {{ $confirmingReport->media_count }} attachment(s). This action cannot be undone.</p>
                @endif
                <footer><button class="button-secondary" type="button" wire:click="cancelDelete">CLOSE</button>@unless($hasConnections)<button class="button-danger" type="button" wire:click="deleteReport" wire:loading.attr="disabled" wire:target="deleteReport"><span wire:loading.remove wire:target="deleteReport">CONFIRM DELETE</span><span wire:loading wire:target="deleteReport">DELETING…</span></button>@endunless</footer>
            </section>
        </div>
    @endif
</div>
