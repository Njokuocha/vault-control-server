@extends('layouts.app')

@php($editing = $person->exists)
@section('page_title', ($editing ? 'Update person' : 'New person') . ' — ' . config('app.name'))
@section('page_label', $editing ? 'UPDATE PROFILE' : 'NEW PROFILE')

@section('content')
    <livewire:person-form-page :person="$person" />
@endsection
