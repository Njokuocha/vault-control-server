@php($editing = $caseFile->exists)
@php($cfmWorkspace = request()->routeIs('cfm.*'))
@extends($cfmWorkspace ? 'layouts.department' : 'layouts.app')
@section('page_title', ($editing ? 'Update case' : 'New case').' — '.config('app.name'))
@if (!$cfmWorkspace)
    @section('page_label', $editing ? 'UPDATE CASE' : 'NEW CASE')
@endif

@section($cfmWorkspace ? 'department_content' : 'content')
    <livewire:case-form-page :case-file="$caseFile" :max-upload-mb="$maxUploadMb" :max-video-upload-mb="$maxVideoUploadMb" />
@endsection
