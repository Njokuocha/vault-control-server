@extends('layouts.department')

@section('page_title', 'File Management — '.config('app.name'))
@section('department_content')
    <div class="cfm-dashboard">
        <section class="page-heading" aria-labelledby="fm-page-title">
            <div>
                <div class="eyebrow"><span class="eyebrow-line"></span> FILE MANAGEMENT · OPERATIONS <span class="eyebrow-index">04 / 04</span></div>
                <h1 id="fm-page-title">File <span>archive</span></h1>
                <p class="page-description">Review the organization’s private archive, viewables, and incoming file activity.</p>
            </div>
            <a class="button-primary" href="{{ route('fm.uploads.create') }}" wire:navigate>UPLOAD FILES <span aria-hidden="true">＋</span></a>
        </section>
        <livewire:dashboard-stats />
        <livewire:dashboard-latest-files />
        <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} SECURE FILE MANAGEMENT</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
    </div>
@endsection
