@extends('layouts.department')

@section('page_title', 'My Profile — '.config('app.name'))
@section('department_content')
    @php($caseManager = auth('web')->user()->department === 'cfm')
    @php($profileManager = auth('web')->user()->department === 'pm')
    @php($departmentStaff = $caseManager || $profileManager)
    <div class="department-dashboard {{ $departmentStaff ? 'cfm-profile-card' : '' }}">
        <span class="section-kicker">READ-ONLY PERSONNEL RECORD</span>
        <div class="department-profile {{ $departmentStaff ? 'cfm-profile-hero' : '' }}">
            @if (auth('web')->user()->profile_photo_path)
                <img class="department-profile__picture {{ $departmentStaff ? 'cfm-profile-picture' : '' }}" src="{{ route(auth('web')->user()->department.'.profile.photo') }}" alt="Profile picture">
            @else
                <div class="department-profile__picture {{ $departmentStaff ? 'cfm-profile-picture' : '' }}">{{ strtoupper(substr(auth('web')->user()->name, 0, 1)) }}</div>
            @endif
            <div class="{{ $departmentStaff ? 'cfm-profile-identity' : '' }}">
                @if ($departmentStaff)
                    <div class="agency-status-tag"><i></i> {{ $caseManager ? 'CASE MANAGER' : 'PROFILE OFFICER' }} · {{ strtoupper(auth('web')->user()->account_status) }}</div>
                @endif
                <h1>{{ auth('web')->user()->name }}</h1>
                @if ($caseManager)
                    <div class="cfm-profile-username"><span>AGENCY USERNAME</span><strong>{{ auth('web')->user()->username }}</strong></div>
                @elseif ($profileManager)
                    <div class="cfm-profile-username"><span>AGENCY USERNAME</span><strong>{{ auth('web')->user()->username }}</strong></div>
                @else
                    <p>{{ auth('web')->user()->username }} · {{ auth('web')->user()->email }}</p>
                    <div class="agency-status-tag"><i></i> {{ strtoupper(auth('web')->user()->department) }} · {{ strtoupper(auth('web')->user()->account_status) }}</div>
                @endif
            </div>
        </div>
        @if ($departmentStaff)
            <div class="cfm-profile-details">
                <div><span>EMAIL ADDRESS</span><strong>{{ auth('web')->user()->email }}</strong></div>
                <div><span>DEPARTMENT</span><strong>{{ $caseManager ? 'CASE FILE MANAGEMENT' : 'PROFILE MANAGEMENT' }}</strong></div>
                <div><span>RANK</span><strong>{{ $caseManager ? 'CASE MANAGER' : 'PROFILE OFFICER' }}</strong></div>
            </div>
        @endif
        <p>Identity and profile information are managed by an administrator. Contact your administrator to request a change.</p>
    </div>
    @if ($departmentStaff)
        @livewire('department-password')
    @endif
@endsection
