@extends('layouts.app')

@section('page_title', 'Manual upload — ' . config('app.name'))
@section('page_label', 'MANUAL UPLOAD')

@section('content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE INGESTION <span class="eyebrow-index">02 / 04</span></div>
            <h1>Manual <span>upload</span></h1>
            <p class="page-description">Securely add one or more files to your private archive.</p>
        </div>
        <a class="back-link" href="{{ route('dashboard.index') }}" wire:navigate><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
    </section>

    <livewire:manual-upload-form :max-upload-mb="$maxUploadMb" :max-video-upload-mb="$maxVideoUploadMb" />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection