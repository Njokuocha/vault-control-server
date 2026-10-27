@php($editing = $caseFile->exists)
<div class="case-create-form-layout" data-case-form-layout>
    <section class="case-category-column">
        <div class="form-section-heading"><span class="section-kicker">01 / EVIDENCE INDEX</span><h2>Image categories</h2><p>Group supporting images by source or subject. Categories without details are saved as Unknown.</p></div>
        <div class="case-category-list" data-case-category-list>
            @if ($editing)
                @foreach ($caseFile->categories as $index => $category)
                    <article class="case-category-editor" data-case-category data-case-index="{{ $index }}" data-case-category-id="{{ $category->id }}">
                        <input type="hidden" name="categories[{{ $index }}][id]" value="{{ $category->id }}">
                        <div class="case-category-editor-head"><span>IMAGE CATEGORY {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span><button type="button" class="category-remove" data-remove-category aria-label="Remove category">×</button></div>
                        <label class="record-field"><span>ADD TITLE</span><input name="categories[{{ $index }}][title]" value="{{ $category->title === 'Unknown' ? '' : $category->title }}" maxlength="255" placeholder="e.g. Witnesses"></label>
                        <label class="record-field"><span>ADD DESCRIPTION</span><textarea name="categories[{{ $index }}][description]" rows="2" maxlength="5000" placeholder="Context for this image group">{{ $category->description === 'Unknown' ? '' : $category->description }}</textarea></label>
                        <div class="case-category-images" data-case-image-list>
                            @foreach ($category->images as $image)
                                <div class="case-evidence-thumb" data-existing-case-image>
                                    @if ($image->type === 'video')
                                        <span class="video-media-mark" aria-hidden="true">▶</span>
                                        <span>{{ $image->original_name }}</span>
                                    @elseif (in_array($image->type, ['document', 'audio'], true))
                                        <a class="case-evidence-document" href="{{ route('cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" aria-label="Download {{ $image->original_name }}">{{ strtoupper(pathinfo($image->original_name, PATHINFO_EXTENSION)) }}</a>
                                    @else
                                        <img src="{{ route('cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="{{ $image->original_name }}">
                                    @endif
                                    <button type="button" data-delete-case-image="{{ route('cases.images.destroy', ['caseFile' => $caseFile, 'image' => $image]) }}" aria-label="Delete evidence image">×</button>
                                </div>
                            @endforeach
                        </div>
                        <div data-case-upload-transfer-tokens></div>
                        <input class="visually-hidden-file" type="file" multiple accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::CASE_EVIDENCE) }}" data-case-document-input>
                        <button type="button" class="case-add-image" data-add-case-document><span>＋</span> ADD DOCUMENT</button>
                    </article>
                @endforeach
            @else
                <article class="case-category-editor" data-case-category data-case-index="0">
                    <div class="case-category-editor-head"><span>IMAGE CATEGORY 01</span><button type="button" class="category-remove" data-remove-category aria-label="Remove category">×</button></div>
                    <label class="record-field"><span>ADD TITLE</span><input name="categories[0][title]" maxlength="255" placeholder="e.g. Witnesses"></label>
                    <label class="record-field"><span>ADD DESCRIPTION</span><textarea name="categories[0][description]" rows="2" maxlength="5000" placeholder="Context for this image group"></textarea></label>
                    <div class="case-category-images" data-case-image-list></div>
                    <div data-case-upload-transfer-tokens></div>
                    <input class="visually-hidden-file" type="file" multiple accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::CASE_EVIDENCE) }}" data-case-document-input>
                    <button type="button" class="case-add-image" data-add-case-document><span>＋</span> ADD DOCUMENT</button>
                </article>
            @endif
        </div>
        <button type="button" class="case-add-category" data-add-case-category>＋ ADD CATEGORY</button>
        <template data-case-category-template>
            <article class="case-category-editor" data-case-category>
                <div class="case-category-editor-head"><span>IMAGE CATEGORY <b data-category-number></b></span><button type="button" class="category-remove" data-remove-category aria-label="Remove category">×</button></div>
                <label class="record-field"><span>ADD TITLE</span><input data-category-field="title" maxlength="255" placeholder="e.g. Witnesses"></label>
                <label class="record-field"><span>ADD DESCRIPTION</span><textarea data-category-field="description" rows="2" maxlength="5000" placeholder="Context for this image group"></textarea></label>
                <div class="case-category-images" data-case-image-list></div>
                <div data-case-upload-transfer-tokens></div>
                <input class="visually-hidden-file" type="file" multiple accept="{{ app(\App\Support\RecordAttachmentTypes::class)->acceptAttribute(\App\Support\RecordAttachmentTypes::CASE_EVIDENCE) }}" data-case-document-input>
                <button type="button" class="case-add-image" data-add-case-document><span>＋</span> ADD DOCUMENT</button>
            </article>
        </template>
    </section>
    <section class="case-main-fields">
        <div class="form-section-heading"><span class="section-kicker">02 / CASE ABSTRACT</span><h2>Case information</h2><p>Assign access level and document the case as information develops.</p></div>
        <label class="case-id-preview"><span>ASSIGNED CASE NUMBER</span><input aria-label="Case number" value="{{ $editing ? $caseFile->public_id : 'Assigned on save' }}" readonly><small>Unique identifier is generated automatically and cannot be edited.</small></label>
        <div class="case-fields-grid">
            <label class="record-field record-field-wide"><span>CASE TITLE</span><input name="title" value="{{ old('title', $editing && $caseFile->title !== 'Unknown' ? $caseFile->title : '') }}" maxlength="255" placeholder="Case title or brief description"></label>
            <label class="record-field"><span>CLASSIFICATION</span><select name="classification">@foreach (['CLASSIFIED', 'HIGHLY SENSITIVE', 'ACCESSIBLE'] as $classification)<option value="{{ $classification }}" @selected(old('classification', $caseFile->classification ?: 'CLASSIFIED') === $classification)>{{ $classification }}</option>@endforeach</select></label>
            <label class="record-field"><span>CASE STATUS</span><select name="case_status">@foreach (['OPEN', 'UNDER REVIEW', 'CLOSED'] as $status)<option value="{{ $status }}" @selected(old('case_status', $caseFile->case_status ?: 'OPEN') === $status)>{{ $status }}</option>@endforeach</select></label>
            <label class="record-field record-field-wide case-narrative-field"><span>CASE NARRATIVE</span><textarea name="narrative" rows="15" maxlength="50000" placeholder="Write the case narrative, timeline, known facts, and investigative context.">{{ old('narrative', $editing && $caseFile->narrative !== 'Unknown' ? $caseFile->narrative : '') }}</textarea></label>
            <label class="record-field record-field-wide statement-field"><span>COMMENTS / CONTINUITY NOTES</span><textarea name="comment" rows="5" maxlength="20000" placeholder="Add updates, source notes, and follow-up items.">{{ old('comment', $editing && $caseFile->comment !== 'Unknown' ? $caseFile->comment : '') }}</textarea></label>
        </div>
        <div class="record-form-footer"><span><b>{{ $editing ? $caseFile->public_id : 'ID ASSIGNED ON SAVE' }}</b><small>IMAGE UPLOADS ARE PRIVATE · MAX 10 MB EACH</small></span><button type="submit" class="button-primary">{{ $editing ? 'SAVE CASE FILE' : 'CREATE CASE' }}</button></div>
    </section>
</div>
