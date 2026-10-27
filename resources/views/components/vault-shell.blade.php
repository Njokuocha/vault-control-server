<div class="shell-window-layer" data-shell-layer
     data-directory-url="{{ route('shell.directory') }}"
     data-preview-url="{{ route('shell.preview') }}"
     data-stream-url="{{ route('shell.stream') }}"
     data-download-url="{{ route('shell.download') }}"
     data-delete-url="{{ route('shell.file.destroy') }}"
     data-mkdir-url="{{ route('shell.directory.create') }}"
     data-copy-url="{{ route('shell.copy') }}"
     data-move-url="{{ route('shell.move') }}"
     aria-live="polite"></div>
<template id="shell-window-template">
    <section class="shell-window" role="region" aria-label="Vault Shell command console" data-shell-window>
        <header class="shell-titlebar" data-shell-drag>
            <div class="shell-title">
                <span class="shell-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m8 7-5 5 5 5M13 17h7"/></svg></span>
                <span><strong>VAULT SHELL</strong><small>SECURE COMMAND INTERFACE</small></span>
            </div>
            <div class="shell-window-actions">
                <button type="button" data-shell-fullscreen aria-label="Toggle full screen" title="Toggle full screen"><svg viewBox="0 0 24 24"><path d="M8 4H4v4M16 4h4v4M20 16v4h-4M4 16v4h4"/></svg></button>
                <button type="button" data-shell-close aria-label="Close console" title="Close"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </div>
        </header>
        <div class="shell-statusbar"><span><i></i> SESSION ENCRYPTED</span><span class="shell-window-id">VS / INSTANCE 01</span></div>
        <div class="shell-output" data-shell-output role="log" aria-live="polite" aria-relevant="additions text">
            <div class="shell-welcome-art"><span class="shell-welcome-seal">{{ config('app.symbol') }}</span><span><b>{{ config('app.short_name') }} SHELL</b><small>{{ config('app.acronym') }} INTERACTIVE CONSOLE · RESTRICTED</small></span></div>
            <div class="shell-welcome-copy">Virtual archive console. Use <b>?help</b> to inspect available commands.</div>
            <div class="shell-welcome-copy shell-welcome-muted">Filesystem access is limited to the private vault root. System commands are not executed.</div>
        </div>
        <form class="shell-prompt" data-shell-form autocomplete="off">
            <label class="shell-prompt-label" data-shell-prompt for="shell-command">{{ config('app.acronym') }} {{ config('app.acronym') }}:/root&gt;</label>
            <input id="shell-command" type="text" data-shell-input spellcheck="false" autocapitalize="off" aria-label="Vault Shell command">
            <span class="shell-cursor" aria-hidden="true"></span>
            <button type="submit" class="shell-submit" aria-label="Run command" title="Run command">↵</button>
        </form>
        <footer class="shell-footer"><span>CTRL + SHIFT + X <b>·</b> TOGGLE CONSOLE</span><span>VAULT SHELL <i>V1.0</i></span></footer>
        <span class="shell-resize-hint" aria-hidden="true"></span>
    </section>
</template>
<div class="file-preview-backdrop" data-file-preview hidden>
    <section class="file-preview-window" role="dialog" aria-modal="true" aria-labelledby="file-preview-title">
        <header class="file-preview-titlebar">
            <div><span class="preview-file-mark" aria-hidden="true">FILE</span><span><strong id="file-preview-title" data-preview-title>File preview</strong><small data-preview-meta></small></span></div>
            <button type="button" data-preview-close aria-label="Close file preview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
        </header>
        <div class="file-preview-content" data-preview-content></div>
        <footer class="file-preview-footer"><span>PRIVATE FILE · AUTHENTICATED SESSION</span><a data-preview-download href="#" download>DOWNLOAD</a></footer>
    </section>
</div>
