<div
    class="case-analysis-portal"
    data-case-analysis
    data-initial-case="{{ $initialCaseId ?? '' }}"
    data-endpoints="{{ json_encode($endpoints, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}"
>
    <header class="case-analysis-toolbar">
        <div class="case-analysis-brand">
            <span class="case-analysis-brand__mark"><i class="ri ri-node-tree" aria-hidden="true"></i></span>
            <div>
                <span class="case-analysis-eyebrow">INVESTIGATION WORKSPACE</span>
                <h1>Case Analysis</h1>
            </div>
        </div>
        <div class="case-analysis-current" data-current-case aria-live="polite">
            <span class="case-analysis-current__marker"></span>
            <div><small>NO CASE IMPORTED</small><strong>Select an investigation case to begin</strong></div>
        </div>
        <div class="case-analysis-toolbar__actions">
            <button type="button" class="case-analysis-button case-analysis-button--primary" data-open-import>
                <i class="ri ri-folder-open-line" aria-hidden="true"></i><span>Import case</span>
            </button>
            <button type="button" class="case-analysis-button" data-toggle-recent aria-controls="case-analysis-recent">
                <i class="ri ri-history-line" aria-hidden="true"></i><span>Recent</span>
            </button>
            <button type="button" class="case-analysis-icon-button" data-toggle-notes aria-label="Open shared analysis notes" title="Shared analysis notes" aria-controls="case-analysis-notes">
                <i class="ri ri-sticky-note-line" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="case-analysis-workspace">
        <div class="case-analysis-canvas" data-canvas tabindex="0" aria-label="Interactive case relationship canvas">
            <div class="case-analysis-world" data-world>
                <svg class="case-analysis-connections" data-connections viewBox="0 0 5200 3600" aria-hidden="true"></svg>
                <div class="case-analysis-nodes" data-nodes></div>
            </div>
            <div class="case-analysis-empty" data-empty>
                <span class="case-analysis-empty__icon"><i class="ri ri-node-tree" aria-hidden="true"></i></span>
                <p class="case-analysis-eyebrow">CASE RELATIONSHIP MAPPING</p>
                <h2>Bring the case into focus</h2>
                <p>Import an authorized case file to explore its recorded reports, people, creator, evidence and recent updates.</p>
                <button type="button" class="case-analysis-button case-analysis-button--primary" data-open-import>
                    <i class="ri ri-folder-open-line" aria-hidden="true"></i><span>Import New Case</span>
                </button>
            </div>
            <div class="case-analysis-stage" data-stage role="status" aria-live="polite" hidden>
                <span class="case-analysis-spinner"></span><span data-stage-label>Preparing case summary…</span>
            </div>
            <div class="case-analysis-error" data-load-error hidden>
                <strong>Some case information could not be loaded.</strong>
                <span data-load-error-message></span>
                <button type="button" data-retry-stage>Retry section</button>
            </div>
            <div class="case-analysis-tooltip" data-tooltip hidden></div>

            <div class="case-analysis-canvas-tools">
                <div class="case-analysis-tool-group">
                    <button type="button" data-zoom-out aria-label="Zoom out" title="Zoom out"><i class="ri ri-subtract-line" aria-hidden="true"></i></button>
                    <span data-zoom-value>100%</span>
                    <button type="button" data-zoom-in aria-label="Zoom in" title="Zoom in"><i class="ri ri-add-line" aria-hidden="true"></i></button>
                    <span class="case-analysis-tool-divider"></span>
                    <button type="button" data-fit-view title="Fit to view"><i class="ri ri-fullscreen-line" aria-hidden="true"></i><span>Fit</span></button>
                    <button type="button" data-reset-view title="Reset view"><i class="ri ri-refresh-line" aria-hidden="true"></i></button>
                </div>
                <div class="case-analysis-tool-group">
                    <button type="button" data-toggle-labels aria-pressed="false" title="Toggle node details"><i class="ri ri-price-tag-3-line" aria-hidden="true"></i><span>Labels</span></button>
                    <button type="button" data-fullscreen aria-label="Enter fullscreen" title="Fullscreen"><i class="ri ri-fullscreen-line" aria-hidden="true"></i></button>
                    <button type="button" data-refresh-diagram title="Refresh case data"><i class="ri ri-loop-right-line" aria-hidden="true"></i></button>
                    <button type="button" data-clear-case title="Clear canvas"><i class="ri ri-delete-bin-line" aria-hidden="true"></i><span>Clear</span></button>
                </div>
            </div>

            <div class="case-analysis-search">
                <i class="ri ri-search-line" aria-hidden="true"></i>
                <input type="search" data-node-search placeholder="Search imported case" aria-label="Search nodes in the imported case" disabled>
                <kbd>/</kbd>
            </div>
            <div class="case-analysis-filters" aria-label="Filter node categories">
                <label><input type="checkbox" data-filter="report" checked><span class="case-analysis-dot case-analysis-dot--report"></span>Reports</label>
                <label><input type="checkbox" data-filter="person" checked><span class="case-analysis-dot case-analysis-dot--person"></span>People</label>
                <label><input type="checkbox" data-filter="staff" checked><span class="case-analysis-dot case-analysis-dot--staff"></span>Staff</label>
                <label><input type="checkbox" data-filter="attachment" checked><span class="case-analysis-dot case-analysis-dot--attachment"></span>Files</label>
                <label><input type="checkbox" data-filter="update" checked><span class="case-analysis-dot case-analysis-dot--update"></span>Updates</label>
            </div>
            <div class="case-analysis-coordinate" data-coordinate>X: 0 | Y: 0 | Zoom: 100%</div>
            <div class="case-analysis-minimap" data-minimap>
                <div class="case-analysis-minimap__heading">CASE MAP</div>
                <svg viewBox="0 0 5200 3600" role="img" aria-label="Case diagram minimap">
                    <g data-minimap-nodes></g>
                    <rect data-minimap-viewport x="0" y="0" width="100" height="100"></rect>
                </svg>
                <button type="button" data-minimap-reset>Reset position</button>
            </div>
            <div class="case-analysis-loading-status" data-status-line hidden></div>
        </div>

        <aside class="case-analysis-inspector" data-inspector hidden aria-label="Selected node details">
            <div class="case-analysis-panel-heading">
                <div><span class="case-analysis-eyebrow" data-inspector-type>RECORD</span><h2 data-inspector-title>Selected record</h2></div>
                <button type="button" data-close-inspector aria-label="Close record details"><i class="ri ri-close-line" aria-hidden="true"></i></button>
            </div>
            <div class="case-analysis-inspector__body" data-inspector-body></div>
            <button type="button" class="case-analysis-button case-analysis-button--wide" data-focus-node><i class="ri ri-focus-3-line" aria-hidden="true"></i>Focus on this node</button>
        </aside>

        <aside class="case-analysis-side-panel" id="case-analysis-recent" data-recent-panel hidden aria-label="Recently imported cases">
            <div class="case-analysis-panel-heading">
                <div><span class="case-analysis-eyebrow">YOUR WORKSPACE</span><h2>Recent cases</h2></div>
                <button type="button" data-close-recent aria-label="Close recent cases"><i class="ri ri-close-line" aria-hidden="true"></i></button>
            </div>
            <p class="case-analysis-panel-intro">The ten most recent cases you imported.</p>
            <div class="case-analysis-list" data-recent-list></div>
        </aside>

        <aside class="case-analysis-side-panel case-analysis-side-panel--notes" id="case-analysis-notes" data-notes-panel hidden aria-label="Shared analysis notes">
            <div class="case-analysis-panel-heading">
                <div><span class="case-analysis-eyebrow">SHARED WITH AUTHORIZED CASE USERS</span><h2>Analysis notes</h2></div>
                <button type="button" data-close-notes aria-label="Close analysis notes"><i class="ri ri-close-line" aria-hidden="true"></i></button>
            </div>
            <p class="case-analysis-panel-intro">Working notes for this case. These are not official reports.</p>
            <form class="case-analysis-note-form" data-note-form>
                <label for="case-analysis-note-body" data-note-form-label>New shared note</label>
                <textarea id="case-analysis-note-body" name="body" maxlength="5000" rows="4" placeholder="Record an observation for authorized case analysts…" required></textarea>
                <div class="case-analysis-note-form__actions">
                    <button type="submit" class="case-analysis-button case-analysis-button--primary" data-note-submit>Save note</button>
                    <button type="button" class="case-analysis-button" data-note-cancel hidden>Cancel edit</button>
                </div>
            </form>
            <div class="case-analysis-list case-analysis-notes-list" data-notes-list></div>
            <div class="case-analysis-panel-message" data-notes-message hidden></div>
        </aside>
    </div>

    <dialog class="case-analysis-dialog" data-import-dialog aria-labelledby="case-analysis-import-title">
        <form method="dialog" class="case-analysis-dialog__close"><button type="submit" aria-label="Close case import"><i class="ri ri-close-line" aria-hidden="true"></i></button></form>
        <div class="case-analysis-dialog__heading">
            <span class="case-analysis-eyebrow">AUTHORIZED RECORD SEARCH</span>
            <h2 id="case-analysis-import-title">Import a case file</h2>
            <p>Search by case ID or title. Only cases available to your account are listed.</p>
            <button type="button" class="case-analysis-dialog__clear" data-clear-selection>Clear current canvas selection</button>
        </div>
        <label class="case-analysis-import-search">
            <i class="ri ri-search-line" aria-hidden="true"></i>
            <input type="search" data-case-search placeholder="Enter at least 2 characters…" autocomplete="off">
            <span data-search-busy hidden><i class="ri ri-loader-4-line" aria-hidden="true"></i></span>
        </label>
        <div class="case-analysis-search-results" data-search-results aria-live="polite"></div>
        <div class="case-analysis-pagination" data-search-pagination hidden>
            <button type="button" data-search-previous>Previous</button><span data-search-page></span><button type="button" data-search-next>Next</button>
        </div>
    </dialog>
    <dialog class="case-analysis-dialog case-analysis-confirm-dialog" data-confirm-dialog aria-labelledby="case-analysis-confirm-title">
        <form method="dialog">
            <div class="case-analysis-dialog__heading">
                <span class="case-analysis-eyebrow">SHARED CASE CONTENT</span>
                <h2 id="case-analysis-confirm-title" data-confirm-title>Confirm action</h2>
                <p data-confirm-message></p>
            </div>
            <div class="case-analysis-confirm-dialog__actions">
                <button type="submit" value="cancel" class="case-analysis-button">Cancel</button>
                <button type="submit" value="confirm" class="case-analysis-button case-analysis-button--danger">Delete note</button>
            </div>
        </form>
    </dialog>
    <div class="case-analysis-toast" data-toast role="status" aria-live="polite"></div>
</div>
