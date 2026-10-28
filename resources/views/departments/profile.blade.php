@extends('layouts.department')

@section('page_title', 'My Profile — '.config('app.name'))
@section('department_content')
    @php($caseManager = auth('web')->user()->department === 'cfm')
    <div class="department-dashboard {{ $caseManager ? 'cfm-profile-card' : '' }}">
        <span class="section-kicker">READ-ONLY PERSONNEL RECORD</span>
        <div class="department-profile {{ $caseManager ? 'cfm-profile-hero' : '' }}">
            @if (auth('web')->user()->profile_photo_path)
                <img class="department-profile__picture {{ $caseManager ? 'cfm-profile-picture' : '' }}" src="{{ route(auth('web')->user()->department.'.profile.photo') }}" alt="Profile picture">
            @else
                <div class="department-profile__picture {{ $caseManager ? 'cfm-profile-picture' : '' }}">{{ strtoupper(substr(auth('web')->user()->name, 0, 1)) }}</div>
            @endif
            <div class="{{ $caseManager ? 'cfm-profile-identity' : '' }}">
                @if ($caseManager)
                    <div class="agency-status-tag"><i></i> CASE MANAGER · {{ strtoupper(auth('web')->user()->account_status) }}</div>
                @endif
                <h1>{{ auth('web')->user()->name }}</h1>
                @if ($caseManager)
                    <div class="cfm-profile-username"><span>AGENCY USERNAME</span><strong>{{ auth('web')->user()->username }}</strong></div>
                @else
                    <p>{{ auth('web')->user()->username }} · {{ auth('web')->user()->email }}</p>
                    <div class="agency-status-tag"><i></i> {{ strtoupper(auth('web')->user()->department) }} · {{ strtoupper(auth('web')->user()->account_status) }}</div>
                @endif
            </div>
        </div>
        @if ($caseManager)
            <div class="cfm-profile-details">
                <div><span>EMAIL ADDRESS</span><strong>{{ auth('web')->user()->email }}</strong></div>
                <div><span>DEPARTMENT</span><strong>CASE FILE MANAGEMENT</strong></div>
                <div><span>RANK</span><strong>CASE MANAGER</strong></div>
            </div>
        @endif
        <p>Identity and profile information are managed by an administrator. Contact your administrator to request a change.</p>
    </div>
    @if ($caseManager)
        @livewire('department-password')
    @endif
@endsection
