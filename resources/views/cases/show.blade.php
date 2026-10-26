@extends('layouts.app')

@section('page_title', $caseFile->public_id . ' — ' . config('app.name'))
@section('page_label', 'CASE DOSSIER')

@section('content')
    <livewire:case-dossier :case-file="$caseFile" />
@endsection
