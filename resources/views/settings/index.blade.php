@extends('layouts.app')

@section('page_title', 'Settings — ' . config('app.name'))
@section('page_label', 'SETTINGS')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> SYSTEM CONFIGURATION <span class="eyebrow-index">02 / 04</span></div><h1 id="settings-title">System <span>settings</span></h1><p class="page-description">Manage your ingestion key and administrator credentials.</p></div>
        <a class="back-link" href="{{ route('dashboard.index') }}" wire:navigate><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
    </section>

    <livewire:settings-upload-key />

    <livewire:settings-upload-policy />
    <livewire:whitelist-ip-policy-toggle />

    <livewire:settings-image-pin />
    <livewire:settings-password />
    <livewire:security-settings />

    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection