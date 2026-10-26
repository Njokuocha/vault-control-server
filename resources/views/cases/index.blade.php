@extends('layouts.app')

@section('page_title', 'Case files — ' . config('app.name'))
@section('page_label', 'CASE FILES')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> CASE MANAGEMENT DIVISION <span class="eyebrow-index">05 / 06</span></div><h1>Case <span>files</span></h1><p class="page-description">Organized case records, evidence categories, and linked person profiles.</p></div>
        <a href="{{ route('cases.create') }}" wire:navigate class="button-primary">NEW CASE ＋</a>
    </section>
    <livewire:case-index />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · CASE MANAGEMENT</span><span>RESTRICTED <i>·</i> AUTHENTICATED ACCESS</span></footer>
@endsection
