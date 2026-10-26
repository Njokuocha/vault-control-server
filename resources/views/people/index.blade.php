@extends('layouts.app')

@section('page_title', 'Person records — ' . config('app.name'))
@section('page_label', 'PERSON RECORDS')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> SUBJECT INDEX <span class="eyebrow-index">04 / 06</span></div><h1>Person <span>records</span></h1><p class="page-description">Private profiles and verified links to associated case files.</p></div>
        <a href="{{ route('people.create') }}" class="button-primary">＋ NEW PERSON</a>
    </section>
    @livewire('people-index')
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · RESTRICTED RECORDS</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
