@extends('layouts.app')

@php($editing = $caseFile->exists)
@section('page_title', ($editing ? 'Update case' : 'New case') . ' — ' . config('app.name'))
@section('page_label', $editing ? 'UPDATE CASE' : 'NEW CASE')

@section('content')
    <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> CASE MANAGEMENT <span class="eyebrow-index">{{ $editing ? $caseFile->public_id : 'INTAKE' }}</span></div><h1>{{ $editing ? 'Update' : 'Create' }} <span>case file</span></h1><p class="page-description">All fields are optional and can be completed as the case develops.</p></div><a class="back-link" href="{{ $editing ? route('cases.show', $caseFile) : route('cases.index') }}">← CANCEL</a></section>
    <form class="case-form-shell" method="POST" action="{{ $editing ? route('cases.update', $caseFile) : route('cases.store') }}" enctype="multipart/form-data" data-video-limit-config data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}">
        @csrf
        @if ($editing)@method('PUT')@endif
        @include('cases._form-fields')
    </form>
@endsection
