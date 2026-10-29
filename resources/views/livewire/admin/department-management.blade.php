<div class="content-inner agency-departments-page">
    <div class="page-heading">
        <div>
            <span class="section-kicker">CENTRAL ADMINISTRATION</span>
            <h1>Department Management</h1>
            <p>Edit department names and staff ranks. Saved departments cannot be deleted.</p>
        </div>
        <a class="button-secondary" href="{{ route('admin.staff.index') }}" wire:navigate>STAFF MANAGEMENT</a>
    </div>

    <section class="glass-panel agency-departments-panel">
        <div class="agency-departments-toolbar">
            <label class="agency-departments-search">
                <span>SEARCH DEPARTMENTS</span>
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, short rank, or rank">
            </label>
            <div class="agency-departments-actions">
                <button class="button-primary" type="button" wire:click="save" wire:loading.attr="disabled" @disabled(!$this->hasChanges())>
                    <span wire:loading.remove wire:target="save">SAVE CHANGES</span>
                    <span wire:loading wire:target="save">SAVING…</span>
                </button>
                <button class="agency-department-add" type="button" wire:click="addRow" aria-label="Add department row" title="Add department row">+</button>
            </div>
        </div>

        <div class="agency-departments-table-wrap">
            <table class="agency-departments-table">
                <thead>
                    <tr>
                        <th scope="col">S/N</th>
                        <th scope="col">DEPARTMENT</th>
                        <th scope="col">SHORT RANK</th>
                        <th scope="col">RANK</th>
                        <th scope="col">STAFF</th>
                        <th scope="col">CREATED AT</th>
                        <th scope="col"><span class="sr-only">Row action</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($visibleRows as $index => $row)
                        <tr wire:key="department-row-{{ $index }}">
                            <td class="agency-department-number">{{ $loop->iteration }}</td>
                            <td>
                                <input aria-label="Department name" type="text" wire:model.live.debounce.250ms="rows.{{ $index }}.name">
                                @error("rows.$index.name")<small class="agency-department-error">{{ $message }}</small>@enderror
                            </td>
                            <td>
                                <input aria-label="Department short rank" type="text" wire:model.live.debounce.250ms="rows.{{ $index }}.rank_short">
                                @error("rows.$index.rank_short")<small class="agency-department-error">{{ $message }}</small>@enderror
                            </td>
                            <td>
                                <input aria-label="Department rank" type="text" wire:model.live.debounce.250ms="rows.{{ $index }}.rank">
                                @error("rows.$index.rank")<small class="agency-department-error">{{ $message }}</small>@enderror
                            </td>
                            <td class="agency-department-count">{{ $row['staff_count'] }}</td>
                            <td class="agency-department-date">{{ $row['created_at'] ?? 'NEW' }}</td>
                            <td>
                                @if ($row['id'] === null)
                                    <button class="agency-department-remove" type="button" wire:click="removeNewRow({{ $index }})" aria-label="Remove unsaved department row">×</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="agency-departments-empty" colspan="7">No departments match this search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="agency-departments-note"><span></span> Saved departments remain available for staff assignment and cannot be removed.</p>
    </section>
</div>
