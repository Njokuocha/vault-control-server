@php($cfmWorkspace = request()->routeIs('cfm.*'))
@extends($cfmWorkspace ? 'layouts.department' : 'layouts.app')

@section('page_title', $caseFile->public_id . ' — ' . config('app.name'))
@if (!$cfmWorkspace)
    @section('page_label', 'CASE DOSSIER')
@endif

@section($cfmWorkspace ? 'department_content' : 'content')
    <livewire:case-dossier :case-file="$caseFile" />
@endsection
