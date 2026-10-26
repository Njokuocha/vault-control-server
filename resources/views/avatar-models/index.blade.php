@extends('layouts.app')

@section('page_title', 'Avatar models — ' . config('app.name'))
@section('page_label', 'AVATAR MODELS')

@section('content')
    <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> IDENTITY VISUAL SYSTEM <span class="eyebrow-index">06 / 06</span></div><h1>Avatar <span>models</span></h1><p class="page-description">Configure private, rotating GLB avatar models for person profiles.</p></div></section>
    <div class="avatar-model-notice"><span>i</span><p>Upload binary glTF 2.0 files (.glb, max 50 MB). Models are private and must contain embedded assets only. Scripts and external URLs are not loaded by the viewer.</p></div>
    <section class="avatar-model-grid">
        @foreach ($groups as $group)
            @php($label = ucfirst($group))
            <article class="avatar-model-card">
                <div class="avatar-model-preview">
                    @if ($models[$group])
                        <model-viewer src="{{ $models[$group] }}" alt="{{ $label }} avatar model preview" auto-rotate auto-rotate-delay="0" rotation-per-second="16deg" interaction-prompt="none" disable-tap shadow-intensity="0.5" exposure="0.9"></model-viewer>
                        <span class="avatar-model-state is-configured"><i></i> MODEL CONFIGURED</span>
                    @else
                        <div class="avatar-model-empty"><span>3D</span><small>NO {{ strtoupper($label) }} MODEL</small></div>
                        <span class="avatar-model-state"><i></i> AWAITING MODEL</span>
                    @endif
                </div>
                <div class="avatar-model-card-content"><span class="section-kicker">PROFILE AVATAR</span><h2>{{ $label }}</h2><p>{{ $label === 'Unknown' ? 'Used when the profile avatar group is not yet identified.' : 'Used for profiles assigned to the '.$label.' avatar group.' }}</p>
                    <form method="POST" action="{{ route('avatar-models.update', $group) }}" enctype="multipart/form-data" data-glb-form>
                        @csrf
                        <input class="visually-hidden-file" type="file" name="model" accept=".glb,model/gltf-binary" required data-glb-input>
                        <button type="button" class="button-secondary" data-glb-select>{{ $models[$group] ? 'REPLACE MODEL' : 'SELECT GLB FILE' }}</button>
                        <span class="glb-file-name" data-glb-file-name>No file selected</span>
                        <button type="submit" class="button-primary">SAVE {{ strtoupper($label) }} MODEL</button>
                    </form>
                </div>
            </article>
        @endforeach
    </section>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · PRIVATE AVATAR LIBRARY</span><span>WEBGL PREVIEW <i>·</i> NO INTERACTION</span></footer>
@endsection
