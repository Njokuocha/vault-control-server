@extends('layouts.department')

@section('page_title', 'Staff Management — '.config('app.name'))
@section('department_content')
    <div class="department-dashboard">
        <span class="section-kicker">STAFF MANAGEMENT WORKSPACE</span>
        <h1>Personnel identity operations</h1>
        <p>Manage staff identity profiles and protected personnel galleries.</p>
        <div class="agency-dashboard-actions">
            <a class="button-primary" href="{{ route('sm.staff.index') }}" wire:navigate><i class="ri ri-team-line" aria-hidden="true"></i> OPEN STAFF DIRECTORY</a>
            <span class="agency-status-tag"><i></i> AUTHENTICATED · STAFF MANAGER</span>
        </div>
    </div>
@endsection
