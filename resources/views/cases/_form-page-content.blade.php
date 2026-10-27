@php($editing = $caseFile->exists)
    <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> CASE MANAGEMENT <span class="eyebrow-index">{{ $editing ? $caseFile->public_id : 'INTAKE' }}</span></div><h1>{{ $editing ? 'Update' : 'Create' }} <span>case file</span></h1><p class="page-description">All fields are optional and can be completed as the case develops.</p></div><a class="back-link" href="{{ $editing ? route('cases.show', $caseFile) : route('cases.index') }}" wire:navigate>← CANCEL</a></section>
    <form class="case-form-shell" method="POST" action="{{ $editing ? route('cases.update', $caseFile) : route('cases.store') }}" enctype="multipart/form-data" data-video-limit-config data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}" data-record-form-submit data-record-upload-form @if($editing) data-record-id="{{ $caseFile->id }}" @else data-record-draft-storage-key="vault.cases.create-draft.v1" @endif data-defer-record-uploads="true" data-submitting-label="SAVING CASE FILE…">
        @csrf
        @if ($editing)@method('PUT')@endif
        @include('cases._form-fields')
    </form>
