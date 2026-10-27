@php($editing = $person->exists)
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> PERSONNEL RECORD <span class="eyebrow-index">PROFILE INTAKE</span></div><h1>{{ $editing ? 'Update' : 'Create' }} <span>profile</span></h1><p class="page-description">Fields may be completed over time. Unprovided information is recorded as Unknown.</p></div>
        <a class="back-link" href="{{ $editing ? route('people.show', $person) : route('people.index') }}" wire:navigate><span aria-hidden="true">←</span> CANCEL</a>
    </section>
    <form class="record-form-shell" method="POST" action="{{ $editing ? route('people.update', $person) : route('people.store') }}" enctype="multipart/form-data" data-video-limit-config data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}" data-record-form-submit data-record-upload-form @if($editing) data-record-id="{{ $person->id }}" @else data-record-draft-storage-key="vault.people.create-draft.v1" @endif data-defer-record-uploads="true" data-submitting-label="SAVING PROFILE…">
        @csrf
        @if ($editing)@method('PUT')@endif
        <section class="record-form-visual">
            <div class="form-section-heading"><span class="section-kicker">01 / IDENTITY VISUAL</span><h2>Profile imagery</h2><p>Upload a primary image and additional gallery images.</p></div>
            <div class="main-image-uploader" data-main-image-uploader>
                @if ($editing && $person->main_image_path && session('unlocked_person_images') === $person->id)
                    <img src="{{ route('people.image', ['person' => $person, 'image' => 'main']) }}" alt="Current main image" data-main-image-preview>
                @elseif ($editing && $person->main_image_path)
                    <div class="main-image-placeholder" data-main-image-placeholder><span>?</span><strong>PIN-LOCKED IMAGE</strong><small>UNLOCK VIEWABLES FROM PROFILE</small></div>
                @else
                    <div class="main-image-placeholder" data-main-image-placeholder><span>+</span><strong>MAIN IMAGE</strong><small>JPG · PNG · WEBP · GIF</small></div>
                @endif
                @if ($editing)
                    <input class="visually-hidden-file" type="file" accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::PERSON_MAIN_IMAGE) }}" data-main-image-input data-record-main-image-input>
                @else
                    <input class="visually-hidden-file" type="file" accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::PERSON_MAIN_IMAGE) }}" data-main-image-input data-record-main-image-input>
                @endif
                <button type="button" class="image-upload-action" data-main-image-trigger>SELECT MAIN IMAGE</button>
                @if ($editing && $person->main_image_path)
                    <label class="remove-main-image"><input type="checkbox" name="remove_main_image" value="1"> REMOVE CURRENT IMAGE</label>
                @endif
            </div>
            <div class="form-subsection-heading"><span>GALLERY MEDIA</span><span class="person-media-actions"><button type="button" class="case-add-image" data-add-person-document aria-label="Add document"><span>＋</span> ADD DOCUMENT</button></span></div>
            <div class="person-gallery-upload-list" data-person-gallery-upload-list>
                <input class="visually-hidden-file" type="file" multiple accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::PERSON_GALLERY) }}" data-person-document-input>
                @if ($editing)
                    @foreach ($person->images as $image)
                        <div class="existing-upload-image" data-existing-image>
                            @if ($image->type === 'video')
                                <span class="video-media-mark" aria-hidden="true">▶</span>
                            @elseif (session('unlocked_person_images') === $person->id)
                                <img src="{{ route('people.image', ['person' => $person, 'image' => $image->id]) }}" alt="{{ $image->original_name }}">
                            @else
                                <span class="image-lock-placeholder">?</span>
                            @endif
                            <span>{{ $image->original_name }}</span>
                            <button type="button" data-delete-person-image="{{ route('people.images.destroy', ['person' => $person, 'image' => $image]) }}" aria-label="Delete {{ $image->original_name }}">×</button>
                        </div>
                    @endforeach
                @endif
                <p class="field-hint">Images are limited to 10 MB. Videos upload in private chunks and attach to this profile when saved.</p>
            </div>
            <div class="form-note"><span>!</span><p>Images are private, authenticated resources. Unsupported image formats and active SVG content are not accepted.</p></div>
        </section>
        <section class="record-form-fields">
            <div class="form-section-heading"><span class="section-kicker">02 / SUBJECT INFORMATION</span><h2>Identity dossier</h2><p>Every field is optional and can be updated later.</p></div>
            <div class="record-fields-grid">
                <label class="record-field record-field-wide"><span>DISPLAY NAME</span><input name="name" value="{{ old('name', $person->name === 'Unknown' ? '' : $person->name) }}" maxlength="255" placeholder="Full display name"></label>
                <label class="record-field"><span>FIRST NAME</span><input name="first_name" value="{{ old('first_name', $person->first_name === 'Unknown' ? '' : $person->first_name) }}" maxlength="255"></label>
                <label class="record-field"><span>LAST NAME</span><input name="last_name" value="{{ old('last_name', $person->last_name === 'Unknown' ? '' : $person->last_name) }}" maxlength="255"></label>
                <label class="record-field"><span>SURNAME</span><input name="surname" value="{{ old('surname', $person->surname === 'Unknown' ? '' : $person->surname) }}" maxlength="255"></label>
                <label class="record-field"><span>AGE</span><input name="age" value="{{ old('age', $person->age === 'Unknown' ? '' : $person->age) }}" maxlength="30" placeholder="Years / estimated"></label>
                <label class="record-field"><span>DATE OF BIRTH</span><input name="date_of_birth" value="{{ old('date_of_birth', $person->date_of_birth === 'Unknown' ? '' : $person->date_of_birth) }}" maxlength="40" placeholder="YYYY-MM-DD or estimated"></label>
                <label class="record-field"><span>GENDER</span><select name="gender"><option value="">Unknown</option>@foreach (['Male', 'Female', 'Other'] as $option)<option value="{{ $option }}" @selected(old('gender', $person->gender) === $option)>{{ $option }}</option>@endforeach</select></label>
                <label class="record-field"><span>AVATAR GROUP</span><select name="avatar_group">@foreach (['Unknown', 'Male', 'Female', 'Group', 'Suspect', 'Verified'] as $option)<option value="{{ $option }}" @selected(old('avatar_group', $person->avatar_group ?: 'Unknown') === $option)>{{ $option }}</option>@endforeach</select></label>
                <label class="record-field"><span>STATUS FLAG</span><select name="flag"><option value="">Unknown</option>@foreach (['Alive', 'Deceased', 'Wanted'] as $option)<option value="{{ $option }}" @selected(old('flag', $person->flag) === $option)>{{ $option }}</option>@endforeach</select></label>
                <label class="record-field"><span>MARITAL STATUS</span><input name="marital_status" value="{{ old('marital_status', $person->marital_status === 'Unknown' ? '' : $person->marital_status) }}" maxlength="60"></label>
                <label class="record-field"><span>SPOUSE</span><input name="spouse" value="{{ old('spouse', $person->spouse === 'Unknown' ? '' : $person->spouse) }}" maxlength="255"></label>
                <label class="record-field"><span>CITIZENSHIP</span><input name="citizenship" value="{{ old('citizenship', $person->citizenship === 'Unknown' ? '' : $person->citizenship) }}" maxlength="100"></label>
                <label class="record-field"><span>STATE / REGION</span><input name="state" value="{{ old('state', $person->state === 'Unknown' ? '' : $person->state) }}" maxlength="100"></label>
                <label class="record-field"><span>BLOOD GROUP</span><input name="blood_group" value="{{ old('blood_group', $person->blood_group === 'Unknown' ? '' : $person->blood_group) }}" maxlength="20"></label>
                <label class="record-field"><span>GENOTYPE</span><input name="genotype" value="{{ old('genotype', $person->genotype === 'Unknown' ? '' : $person->genotype) }}" maxlength="20"></label>
                <label class="record-field"><span>HEIGHT</span><input name="height" value="{{ old('height', $person->height === 'Unknown' ? '' : $person->height) }}" maxlength="40" placeholder="e.g. 178 cm"></label>
                <label class="record-field"><span>OCCUPATION</span><input name="occupation" value="{{ old('occupation', $person->occupation === 'Unknown' ? '' : $person->occupation) }}" maxlength="255"></label>
                <label class="record-field"><span>ALIASES</span><input name="aliases" value="{{ old('aliases', $person->aliases === 'Unknown' ? '' : $person->aliases) }}" maxlength="255"></label>
                <label class="record-field record-field-wide"><span>LAST KNOWN LOCATION</span><input name="last_known_location" value="{{ old('last_known_location', $person->last_known_location === 'Unknown' ? '' : $person->last_known_location) }}" maxlength="255"></label>
                <label class="record-field record-field-wide"><span>IDENTIFYING MARKS / DETAILS</span><textarea name="identifying_marks" rows="3" maxlength="20000" placeholder="Scars, distinguishing features, or other relevant details">{{ old('identifying_marks', $person->identifying_marks === 'Unknown' ? '' : $person->identifying_marks) }}</textarea></label>
                <label class="record-field record-field-wide statement-field"><span>COMMENT / STATEMENT</span><textarea name="statement" rows="7" maxlength="20000" placeholder="Record sourced statements, investigator comments, or context.">{{ old('statement', $person->statement === 'Unknown' ? '' : $person->statement) }}</textarea><small>Record source/date where applicable. Unverified statements should be identified as such.</small></label>
            </div>
            <div class="record-form-footer"><span><b>{{ $editing ? $person->public_id : 'ID ASSIGNED ON SAVE' }}</b><small>UNFILLED FIELDS WILL BE STORED AS UNKNOWN</small></span><button type="submit" class="button-primary">{{ $editing ? 'SAVE PROFILE' : 'CREATE PROFILE' }}</button></div>
        </section>
    </form>
