@extends('layouts.department')

@php($editing = $person->exists)
@section('page_title', ($editing ? 'Update person profile' : 'New person profile').' — '.config('app.name'))
@section('department_content')
    <livewire:person-form-page :person="$person" route-prefix="pm.people" />
@endsection
