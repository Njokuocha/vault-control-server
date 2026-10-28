@extends('layouts.department')

@section('page_title', $departmentName.' — '.config('app.name'))
@section('department_content')
    <div class="department-dashboard">
        <span class="section-kicker">{{ strtoupper($department) }} DEPARTMENT</span>
        <h1>{{ $departmentName }}</h1>
        <p>Welcome, {{ auth('web')->user()->name }}. Department tools are being brought online in the approved rollout sequence.</p>
        <div class="agency-status-tag"><i></i> AUTHENTICATED · {{ strtoupper(auth('web')->user()->account_status) }}</div>
    </div>
@endsection
