@extends('layouts.department')

@section('page_title', 'Person Profiles — '.config('app.name'))
@section('department_content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PROFILE INDEX <span class="eyebrow-index">IDENTITY RECORDS</span></div>
            <h1>Person <span>profiles</span></h1>
            <p class="page-description">Manage person identity records and protected profile media.</p>
        </div>
        <a href="{{ route('pm.people.create') }}" wire:navigate class="button-primary">＋ NEW PROFILE</a>
    </section>
    @livewire('people-index')
@endsection
