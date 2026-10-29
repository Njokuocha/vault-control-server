@extends('layouts.department')

@section('page_title', 'Manual upload — '.config('app.name'))
@section('department_content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE INGESTION <span class="eyebrow-index">FILE MANAGEMENT</span></div>
            <h1>Manual <span>upload</span></h1>
            <p class="page-description">Securely add one or more files to the organization’s private archive.</p>
        </div>
        <a class="back-link" href="{{ route('fm.dashboard') }}" wire:navigate><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
    </section>

    <livewire:manual-upload-form :max-upload-mb="$maxUploadMb" :max-video-upload-mb="$maxVideoUploadMb" />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
