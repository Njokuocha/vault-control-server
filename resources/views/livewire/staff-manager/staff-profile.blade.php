<div class="content-inner agency-staff-profile-page">
    <div class="page-heading">
        <div><span class="section-kicker">STAFF MANAGEMENT · PERSONNEL RECORD</span><h1>Staff <span>profile</span></h1><p>Identity information and protected gallery access for {{ $staff->name }}.</p></div>
        <a class="button-secondary" href="{{ route('sm.staff.index', ['search' => $staff->username]) }}" wire:navigate>STAFF DIRECTORY</a>
    </div>
    <section class="glass-panel agency-staff-profile-hero">
        @if ($staff->profile_photo_path)
            <img class="agency-staff-profile-photo" src="{{ route('sm.staff.photo', $staff) }}" alt="{{ $staff->name }} profile picture">
        @else
            <div class="agency-staff-profile-photo agency-staff-profile-photo--initial">{{ strtoupper(substr($staff->name, 0, 1)) }}</div>
        @endif
        <div class="agency-staff-profile-identity">
            <span class="section-kicker">{{ $staff->departmentRecord?->rank_short ?? strtoupper($staff->department) }} · {{ strtoupper($staff->account_status ?? 'active') }}</span>
            <h2>{{ $staff->name }}</h2>
            <p>{{ $staff->departmentRecord?->name ?? $staff->department }} · {{ $staff->departmentRecord?->rank ?? 'Agency staff' }}</p>
        </div>
        <div class="agency-staff-profile-actions">
            <a class="button-secondary" href="{{ route('sm.staff.index', ['search' => $staff->username]) }}" wire:navigate>MANAGE IDENTITY</a>
            <a class="button-primary" href="{{ route('sm.staff.gallery.index', $staff) }}" wire:navigate>VIEW GALLERY</a>
        </div>
    </section>
    <section class="agency-staff-profile-facts" aria-label="Staff identity details">
        <article class="glass-panel"><span>USERNAME</span><strong>{{ $staff->username ?: 'Not set' }}</strong></article>
        <article class="glass-panel"><span>EMAIL ADDRESS</span><strong>{{ $staff->email }}</strong></article>
        <article class="glass-panel"><span>DEPARTMENT</span><strong>{{ $staff->departmentRecord?->name ?? $staff->department }}</strong></article>
        <article class="glass-panel"><span>ACCOUNT CREATED</span><strong>{{ $staff->created_at?->format('M d, Y · H:i') ?? 'Unknown' }}</strong></article>
    </section>
</div>
