<section class="stats-grid" aria-label="Archive statistics">
    <article class="stat-card stat-card-featured">
        <div class="stat-top"><span class="stat-label">TOTAL FILES</span><span class="stat-icon stat-icon-gold" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3.8h7l5 5v11.4H6z"/><path d="M13 3.8v5h5M9 13h6M9 16h6"/></svg></span></div>
        <div class="stat-value" data-dashboard-stat="fileCount">{{ number_format($fileCount) }}</div>
        <div class="stat-foot"><span class="stat-caption">INDEXED RECORDS</span><span class="stat-foot-mark">01</span></div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span class="stat-label">TOTAL STORAGE</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8V5.5M4.5 11.9v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-6.4"/></svg></span></div>
        <div class="stat-value" data-dashboard-stat="storageLabel">{{ $storageLabel }}</div>
        <div class="stat-foot"><span class="stat-caption">PRIVATE DISK USAGE</span><span class="stat-foot-mark">02</span></div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span class="stat-label">THIS MONTH</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5.5" width="16" height="15" rx="1.5"/><path d="M8 3.5v4M16 3.5v4M4 10h16M8 14h2M13 14h3M8 17h2"/></svg></span></div>
        <div class="stat-value" data-dashboard-stat="uploadedThisMonth">{{ number_format($uploadedThisMonth) }}</div>
        <div class="stat-foot"><span class="stat-caption">NEW ADDITIONS</span><span class="stat-foot-mark">03</span></div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span class="stat-label">LAST ACTIVITY</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/></svg></span></div>
        <div class="stat-value stat-value-text" data-dashboard-stat="lastActivity">{{ $lastActivity ? \Illuminate\Support\Carbon::parse($lastActivity)->format('M j, H:i') : '—' }}</div>
        <div class="stat-foot"><span class="stat-caption">{{ $lastActivity ? 'LATEST FILE ADDED' : 'NO RECENT EVENTS' }}</span><span class="stat-foot-mark">04</span></div>
    </article>
</section>
