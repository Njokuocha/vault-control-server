@php($cfmWorkspace = request()->routeIs('cfm.*'))
@extends($cfmWorkspace ? 'layouts.department' : 'layouts.app')

@section('page_title', 'Case files — '.config('app.name'))
@if (!$cfmWorkspace)
    @section('page_label', 'CASE FILES')
@endif

@section($cfmWorkspace ? 'department_content' : 'content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> CASE FILE MANAGEMENT <span class="eyebrow-index">RESTRICTED</span></div><h1>Case <span>files</span></h1><p class="page-description">Organized case records, evidence categories, and supporting material.</p></div>
        <a href="{{ route(($cfmWorkspace ? 'cfm.' : '').'cases.create') }}" wire:navigate class="button-primary">NEW CASE ＋</a>
    </section>
    <livewire:case-index />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · CASE MANAGEMENT</span><span>RESTRICTED <i>·</i> AUTHENTICATED ACCESS</span></footer>
@endsection
