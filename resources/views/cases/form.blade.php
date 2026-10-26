@extends('layouts.app')

@php($editing = $caseFile->exists)
@section('page_title', ($editing ? 'Update case' : 'New case') . ' — ' . config('app.name'))
@section('page_label', $editing ? 'UPDATE CASE' : 'NEW CASE')

@section('content')
    <livewire:case-form-page :case-file="$caseFile" :max-upload-mb="$maxUploadMb" :max-video-upload-mb="$maxVideoUploadMb" />
@endsection
