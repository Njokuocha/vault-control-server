<div class="department-dashboard staff-manager-dashboard">
    <header class="staff-overview-heading">
        <div>
            <span class="section-kicker">STAFF MANAGEMENT · OPERATIONAL OVERVIEW</span>
            <h1>Personnel workspace</h1>
            <p>Account posture, authentication activity, and security signals across agency staff.</p>
        </div>
        <a class="button-primary" href="{{ route('sm.staff.index') }}" wire:navigate><i class="ri ri-team-line" aria-hidden="true"></i> OPEN STAFF DIRECTORY</a>
    </header>
    @include('components.staff-overview-cards', ['metrics' => $metrics, 'routePrefix' => $routePrefix])
    <p class="staff-overview-note"><i class="ri ri-information-line" aria-hidden="true"></i> Session and authentication indicators reflect recorded activity; incident IP details are restricted to authenticated Staff Management personnel.</p>
</div>
