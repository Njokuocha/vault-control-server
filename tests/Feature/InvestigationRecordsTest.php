<?php

use App\Livewire\CaseDossier;
use App\Livewire\CaseFormPage;
use App\Livewire\CaseIndex;
use App\Livewire\PeopleIndex;
use App\Models\Admin;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\File as VaultFile;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonImage;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function investigationImageUpload(string $name): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=', true);

    return UploadedFile::fake()->createWithContent($name, $png);
}

beforeEach(function () {
    config(['vault.upload_disk' => 'vault']);
    Storage::fake('vault');
});

test('person profiles are optional, receive unique public identifiers, and store blank fields as unknown', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('people.store'), [])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    expect($person->public_id)->toMatch('/^USR-[A-Z0-9]{7}$/')
        ->and($person->name)->toBe('Unknown')
        ->and($person->first_name)->toBe('Unknown')
        ->and($person->statement)->toBe('Unknown')
        ->and($person->flag)->toBe('Unknown');

    $this->post(route('people.store'), [])->assertRedirect();
    $secondPerson = Person::query()->latest('id')->firstOrFail();
    expect($secondPerson->public_id)->not->toBe($person->public_id);

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('NO IMAGE FOR THE CURRENT USER')
        ->assertSee('Linked case files')
        ->assertSee('Unknown');

    $this->get(route('people.index'))->assertOk()->assertSee($person->public_id);
    $this->get(route('people.edit', $person))
        ->assertOk()
        ->assertSee('wire:id', false)
        ->assertSee('Identity dossier')
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-defer-record-uploads="true"', false)
        ->assertSee('data-submitting-label="SAVING PROFILE…"', false)
        ->assertSee('class="case-add-image" data-add-person-document', false)
        ->assertSee('data-record-main-image-input', false)
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO')
        ->assertDontSee('.pdf')
        ->assertSee('name="avatar_group"', false)
        ->assertSee('>Suspect</option>', false)
        ->assertSee('>Verified</option>', false);
    $this->get(route('people.create'))
        ->assertOk()
        ->assertSee('wire:id', false)
        ->assertSee('method="POST"', false)
        ->assertSee('data-defer-record-uploads="true"', false)
        ->assertSee('data-submitting-label="SAVING PROFILE…"', false)
        ->assertSee('data-person-document-input', false)
        ->assertSee('data-record-main-image-input', false);
});

test('case create and edit forms expose one multi-file document picker with case evidence types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('cases.create'))
        ->assertOk()
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-record-draft-storage-key="vault.cases.create-draft.v1"', false)
        ->assertSee('data-defer-record-uploads="true"', false)
        ->assertSee('data-submitting-label="SAVING CASE FILE…"', false)
        ->assertSee('ADD DOCUMENT')
        ->assertSee('data-case-document-input', false)
        ->assertSee('.pdf', false)
        ->assertSee('.xlsx', false)
        ->assertSee('.mp3', false)
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO');

    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-FORM-EDIT',
        'title' => 'Editable evidence case',
    ]);
    $category = $caseFile->categories()->create(['title' => 'Reports']);
    $this->get(route('cases.edit', $caseFile))
        ->assertOk()
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-record-id="'.$caseFile->id.'"', false)
        ->assertSee('data-defer-record-uploads="true"', false)
        ->assertSee('data-submitting-label="SAVING CASE FILE…"', false)
        ->assertSee('data-case-category-id="'.$category->id.'"', false)
        ->assertSee('ADD DOCUMENT')
        ->assertSee('.pdf', false)
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO');
});

test('case create and update return SPA destinations for JSON submissions', function () {
    $user = User::factory()->create();
    $targetKey = (string) Str::uuid();
    $created = $this->actingAs($user)
        ->postJson(route('cases.store'), [
            'title' => 'SPA-created case',
            'record_upload_draft_token' => (string) Str::uuid(),
            'categories' => [
                ['title' => 'Reports', 'upload_target_key' => $targetKey],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Case file created.')
        ->assertJsonPath('redirect_url', route('cases.show', InvestigationCase::query()->where('title', 'SPA-created case')->firstOrFail()));

    $caseFile = InvestigationCase::query()->where('title', 'SPA-created case')->firstOrFail();
    $created->assertJsonPath('record_id', $caseFile->id)
        ->assertJsonPath('upload_targets.'.$targetKey, $caseFile->categories()->firstOrFail()->id);

    $this->putJson(route('cases.update', $caseFile), [
        'title' => 'SPA-updated case',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Case file updated.')
        ->assertJsonPath('redirect_url', route('cases.show', $caseFile));
});

test('case managers can manage case records without seeing profile details or deleting cases', function () {
    $caseManager = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-CFM-ACCESS',
        'title' => 'Restricted case record',
        'narrative' => 'Case manager narrative.',
    ]);
    $caseFile->categories()->create(['title' => 'Evidence']);
    $person = Person::query()->create([
        'public_id' => 'USR-CFM-HIDDEN',
        'name' => 'Profile details unavailable to CFM',
    ]);
    $caseFile->people()->attach($person);
    $this->actingAs($caseManager);

    $this->get(route('cfm.cases.index'))
        ->assertOk()
        ->assertSee($caseFile->title)
        ->assertDontSee('PEOPLE')
        ->assertSee('data-upload-tracker-toggle', false)
        ->assertSee('id="upload-tracker-panel"', false)
        ->assertSee('data-file-preview', false);
    $this->get(route('cfm.cases.show', $caseFile))
        ->assertOk()
        ->assertSee('Case manager narrative.')
        ->assertDontSee($person->name)
        ->assertDontSee('PERSON INDEX');
    $this->get(route('cfm.cases.create'))
        ->assertOk()
        ->assertSee('action="'.route('cfm.cases.store').'"', false)
        ->assertSee('data-case-document-input', false);
    $created = $this->postJson(route('cfm.cases.store'), [
        'title' => 'Created by case manager',
        'record_upload_draft_token' => (string) Str::uuid(),
    ])->assertOk()
        ->assertJsonPath('message', 'Case file created.');
    $createdCase = InvestigationCase::query()->findOrFail($created->json('record_id'));
    $created->assertJsonPath('redirect_url', route('cfm.cases.show', $createdCase));
    expect($createdCase->created_by_user_id)->toBe($caseManager->id)
        ->and($caseManager->createdCases()->whereKey($createdCase->id)->exists())->toBeTrue();
    $this->get(route('cfm.cases.show', $createdCase))
        ->assertOk()
        ->assertSee('Created by '.$caseManager->name);
    $this->putJson(route('cfm.cases.update', $createdCase), [
        'title' => 'Updated by case manager',
    ])->assertOk()
        ->assertJsonPath('redirect_url', route('cfm.cases.show', $createdCase));
    $this->get(route('cases.index'))->assertForbidden();
    $this->delete('/cfm/cases/'.$caseFile->getKey())->assertMethodNotAllowed();
});

test('administrator case deletion removes case evidence and its associated upload records', function () {
    $legacy = User::factory()->create(['email' => 'admin@example.test']);
    config(['vault.admin.email' => $legacy->email]);
    $admin = Admin::query()->create([
        'name' => $legacy->name,
        'email' => $legacy->email,
        'password' => $legacy->password,
    ]);
    $caseFile = InvestigationCase::query()->create([
        'public_id' => 'CSE-DELETE-01',
        'title' => 'Case scheduled for deletion',
    ]);
    $category = $caseFile->categories()->create(['title' => 'Evidence']);
    $path = 'cases/'.$caseFile->id.'/evidence/evidence.png';
    Storage::disk('vault')->put($path, 'private evidence');
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'evidence.png',
        'stored_path' => $path,
        'extension' => 'png',
        'mime_type' => 'image/png',
        'category' => 'image',
        'size' => strlen('private evidence'),
        'checksum' => hash('sha256', 'private evidence'),
    ]);
    $category->images()->create([
        'file_id' => $file->getKey(),
        'path' => $path,
        'original_name' => 'evidence.png',
        'type' => 'image',
    ]);
    $staff = User::factory()->create(['department' => 'cfm']);
    $transfer = $staff->uploadTransfers()->create([
        'token' => (string) Str::uuid(),
        'attachment_purpose' => 'case_evidence',
        'record_type' => 'case',
        'record_id' => $caseFile->getKey(),
        'target_id' => $category->getKey(),
        'committed_at' => now(),
        'attached_at' => now(),
        'original_name' => 'evidence.png',
        'total_size' => 16,
        'chunk_size' => 1048576,
        'chunk_count' => 1,
        'status' => 'done',
        'file_id' => $file->getKey(),
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('upload-transfer-chunks/'.$transfer->token.'/0.part', 'chunk');
    $this->actingAs($legacy, 'web')->actingAs($admin, 'admin');

    $this->delete(route('cases.destroy', $caseFile))
        ->assertRedirect(route('cases.index'));

    expect(InvestigationCase::query()->find($caseFile->getKey()))->toBeNull()
        ->and(UploadTransfer::query()->find($transfer->getKey()))->toBeNull()
        ->and(VaultFile::query()->find($file->getKey()))->toBeNull();
    Storage::disk('vault')->assertMissing($path);
    Storage::disk('local')->assertMissing('upload-transfer-chunks/'.$transfer->token.'/0.part');
});

test('person edit returns an SPA destination and message for JSON submissions', function () {
    $person = Person::query()->create([
        'public_id' => 'USR-SPA-EDIT',
        'name' => 'Existing SPA person',
    ]);

    $this->actingAs(User::factory()->create())
        ->putJson(route('people.update', $person), [
            'name' => 'Updated SPA person',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Person profile updated.')
        ->assertJsonPath('redirect_url', route('people.show', $person));
});

test('people index searches live and returns only matching profiles', function () {
    $this->actingAs(User::factory()->create());
    Person::query()->create(['public_id' => 'USR-LIVE01', 'name' => 'Aster Example']);
    Person::query()->create(['public_id' => 'USR-LIVE02', 'name' => 'Boreal Example']);

    Livewire::test(PeopleIndex::class)
        ->set('search', 'Aster')
        ->assertSee('Aster Example')
        ->assertDontSee('Boreal Example');
});

test('case index cache reflects case creation, edits, and deletion immediately', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('cases.index'))
        ->assertOk()
        ->assertDontSee('Cache Test Case');

    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-CACHE01',
        'title' => 'Cache Test Case',
        'classification' => 'CLASSIFIED',
        'case_status' => 'OPEN',
    ]);

    $this->get(route('cases.index'))
        ->assertSee('Cache Test Case');
    Livewire::test(CaseIndex::class)
        ->assertSee('Cache Test Case');

    $case->update(['title' => 'Renamed Cache Test Case']);
    $this->get(route('cases.index'))
        ->assertSee('Renamed Cache Test Case')
        ->assertDontSee('>Cache Test Case</strong>', false);
    Livewire::test(CaseIndex::class)
        ->assertSee('Renamed Cache Test Case')
        ->assertDontSee('>Cache Test Case</strong>', false);

    $case->delete();
    $this->get(route('cases.index'))
        ->assertDontSee('Renamed Cache Test Case');
});

test('case dossiers and forms render through Livewire with document upload controls', function () {
    $this->actingAs(User::factory()->create());
    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-LIVE001',
        'title' => 'Livewire case dossier',
        'classification' => 'CLASSIFIED',
        'case_status' => 'OPEN',
        'narrative' => 'Dossier narrative.',
        'comment' => 'Continuity note.',
    ]);

    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('wire:id', false)
        ->assertSee('Livewire case dossier')
        ->assertSee('data-vault-image-viewer', false);

    Livewire::test(CaseDossier::class, ['caseFile' => $case])
        ->assertSee('Livewire case dossier')
        ->assertSee('Dossier narrative.')
        ->assertSee('Continuity note.');

    $this->get(route('cases.create'))
        ->assertOk()
        ->assertSee('wire:id', false)
        ->assertSee('method="POST"', false)
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-case-document-input', false)
        ->assertSee('ADD DOCUMENT')
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO');
    $this->get(route('cases.edit', $case))
        ->assertOk()
        ->assertSee('wire:id', false)
        ->assertSee('method="POST"', false)
        ->assertSee('name="_method" value="PUT"', false)
        ->assertSee('data-record-form-submit', false)
        ->assertSee('data-case-document-input', false)
        ->assertSee('ADD DOCUMENT')
        ->assertDontSee('ADD IMAGE')
        ->assertDontSee('ADD VIDEO');

    Livewire::test(CaseFormPage::class, [
        'caseFile' => new InvestigationCase,
        'maxUploadMb' => 100,
        'maxVideoUploadMb' => 0,
    ])->assertSee('CREATE CASE')
        ->assertSee('data-case-document-input', false)
        ->assertSee('ADD DOCUMENT');
});

test('cached profile and case galleries refresh immediately after media changes', function () {
    $this->actingAs(User::factory()->create());
    $person = Person::query()->create([
        'public_id' => 'USR-MEDIA01',
        'name' => 'Gallery Cache Profile',
    ]);
    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-MEDIA01',
        'title' => 'Gallery Cache Case',
        'classification' => 'CLASSIFIED',
        'case_status' => 'OPEN',
    ]);

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('No additional images have been added.');
    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('No image categories have been added.');

    PersonImage::query()->create([
        'person_id' => $person->id,
        'path' => 'people/gallery-cache.jpg',
        'original_name' => 'profile-cache-image.jpg',
    ]);
    $category = CaseCategory::query()->create([
        'case_file_id' => $case->id,
        'title' => 'Cached category',
        'sort_order' => 1,
    ]);
    CaseImage::query()->create([
        'case_category_id' => $category->id,
        'path' => 'cases/gallery-cache.jpg',
        'original_name' => 'case-cache-image.jpg',
    ]);

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('profile-cache-image.jpg');
    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee('case-cache-image.jpg');
});

test('person profiles securely store a main image and multiple gallery images', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)
        ->post(route('people.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'gender' => 'Female',
            'main_image' => investigationImageUpload('portrait.png'),
            'other_images' => [
                investigationImageUpload('evidence-one.png'),
                investigationImageUpload('evidence-two.png'),
            ],
        ])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    expect($person->name)->toBe('Ada Lovelace')
        ->and($person->avatar_group)->toBe('Female')
        ->and($person->main_image_path)->not->toBeNull()
        ->and($person->images()->count())->toBe(2)
        ->and(Storage::disk('vault')->exists($person->main_image_path))->toBeTrue();

    app(SettingService::class)->set('person_image_pin_hash', Hash::make('0427'));
    $this->actingAs($admin)
        ->postJson(route('people.images.unlock', $person), ['pin' => '0427'])
        ->assertOk();

    $this->get(route('people.image', ['person' => $person, 'image' => 'main']))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('person galleries expand beyond four images and expose the gallery expansion control', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('people.store'), [
            'other_images' => array_map(
                fn (int $index): UploadedFile => investigationImageUpload('gallery-'.$index.'.png'),
                range(1, 6),
            ),
        ])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $response = $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('data-profile-gallery', false)
        ->assertSee('+2')
        ->assertSee('data-gallery-extra', false);

    expect(substr_count($response->getContent(), 'data-gallery-extra'))->toBe(2);
});

test('case files create grouped evidence and link to person profiles', function () {
    $user = User::factory()->create();
    $person = Person::query()->create([
        'public_id' => 'USR-ABC1234',
        'name' => 'Unknown',
        'first_name' => 'Unknown',
        'last_name' => 'Unknown',
        'surname' => 'Unknown',
        'age' => 'Unknown',
        'date_of_birth' => 'Unknown',
        'marital_status' => 'Unknown',
        'spouse' => 'Unknown',
        'gender' => 'Unknown',
        'citizenship' => 'Unknown',
        'state' => 'Unknown',
        'blood_group' => 'Unknown',
        'genotype' => 'Unknown',
        'height' => 'Unknown',
        'flag' => 'Unknown',
        'occupation' => 'Unknown',
        'aliases' => 'Unknown',
        'last_known_location' => 'Unknown',
        'identifying_marks' => 'Unknown',
        'avatar_group' => 'Group',
        'statement' => 'Unknown',
    ]);

    $this->actingAs($user)
        ->post(route('cases.store'), [
            'title' => 'Investigation file',
            'classification' => 'HIGHLY SENSITIVE',
            'narrative' => 'Initial case details.',
            'categories' => [[
                'title' => 'Witnesses',
                'description' => 'Witness of the event',
                'images' => [investigationImageUpload('witness.png')],
            ]],
        ])
        ->assertRedirect();

    $case = InvestigationCase::query()->with('categories.images')->firstOrFail();
    expect($case->public_id)->toMatch('/^CSE-[A-Z0-9]{7}$/')
        ->and($case->classification)->toBe('HIGHLY SENSITIVE')
        ->and($case->categories)->toHaveCount(1)
        ->and($case->categories[0]->images)->toHaveCount(1);

    $this->postJson(route('people.cases.attach', $person), ['case_id' => $case->id])
        ->assertOk()
        ->assertJsonPath('status', 'linked');

    expect($person->cases()->whereKey($case->id)->exists())->toBeTrue();

    $this->get(route('cases.index'))->assertOk()->assertSee($case->public_id);
    $this->get(route('cases.edit', $case))->assertOk()->assertSee('CASE ABSTRACT');
    $this->put(route('cases.update', $case), [
        'title' => 'Updated investigation',
        'classification' => 'ACCESSIBLE',
        'case_status' => 'UNDER REVIEW',
        'narrative' => 'Updated case details.',
        'comment' => 'Follow-up notes.',
        'categories' => [[
            'id' => $case->categories[0]->id,
            'title' => 'Updated witnesses',
            'description' => 'Updated evidence description.',
        ]],
    ])->assertRedirect(route('cases.show', $case));

    $case->refresh();
    $case->load('categories.images');
    expect($case->title)->toBe('Updated investigation')
        ->and($case->classification)->toBe('ACCESSIBLE')
        ->and($case->categories[0]->title)->toBe('Updated witnesses')
        ->and($case->categories[0]->images)->toHaveCount(1);

    $this->get(route('cases.show', $case))
        ->assertOk()
        ->assertSee($case->public_id)
        ->assertSee('Updated witnesses')
        ->assertSee('Linked people');

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee($case->public_id)
        ->assertSee('Linked case files');
});

test('investigative record routes require authentication', function () {
    $this->get(route('people.index'))->assertRedirect(route('login'));
    $this->get(route('cases.index'))->assertRedirect(route('login'));
    $this->get(route('avatar-models.index'))->assertRedirect(route('login'));
});

test('case file index links to the full-page new-case form when there are no existing cases', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('cases.index'))
        ->assertOk()
        ->assertSee('href="'.route('cases.create').'"', false)
        ->assertDontSee('data-new-case-dialog', false);

    $this->get(route('cases.create'))
        ->assertOk()
        ->assertSee('Create <span>case file</span>', false)
        ->assertSee('case-form-shell', false)
        ->assertSee('CREATE CASE');
});

test('person images require a PIN on every profile visit and downloads stay authenticated', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)
        ->post(route('people.store'), [
            'gender' => '',
            'main_image' => investigationImageUpload('private-main.png'),
            'other_images' => [investigationImageUpload('private-gallery.png')],
        ])
        ->assertRedirect();

    $person = Person::query()->firstOrFail();
    $this->post(route('settings.person-image-pin.update'), [
        'image_pin' => '0427',
        'image_pin_confirmation' => '0427',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(SettingService::class)->get('person_image_pin_hash'))->not->toBe('0427');

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('data-image-pin-dialog', false)
        ->assertSee('data-image-pin-form', false)
        ->assertSee('type="submit" class="button-primary pin-submit"', false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('data-vault-image-zoom-in', false)
        ->assertSee('data-vault-image-zoom-out', false)
        ->assertSee('data-vault-image-grayscale', false)
        ->assertSee('data-vault-image-stage', false)
        ->assertSee('UNKNOWN MODEL NOT CONFIGURED')
        ->assertDontSee('src="'.route('people.image', ['person' => $person, 'image' => 'main']).'"', false)
        ->assertSee('data-image-url="'.route('people.image', ['person' => $person, 'image' => 'main']).'"', false);
    $this->get(route('people.image', ['person' => $person, 'image' => 'main']))->assertForbidden();
    $this->postJson(route('people.images.unlock', $person), ['pin' => '9999'])->assertUnprocessable();
    $this->postJson(route('people.images.unlock', $person), ['pin' => '0427'])
        ->assertOk()
        ->assertJsonPath('status', 'unlocked');

    $this->get(route('people.image', ['person' => $person, 'image' => 'main']))->assertOk();
    $download = $this->get(route('people.images.download', ['person' => $person, 'image' => 'main']))
        ->assertOk();
    expect($download->headers->get('Content-Disposition'))
        ->toStartWith('attachment; filename=')
        ->toEndWith('.png');
    $this->get(route('people.image', ['person' => $person, 'image' => $person->images()->first()->id]))->assertOk();

    $this->get(route('people.show', $person))->assertOk();
    $this->get(route('people.image', ['person' => $person, 'image' => 'main']))->assertForbidden();
});

test('case evidence galleries expand beyond four images and provide image download routes', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('cases.store'), [
            'title' => 'Expanded image gallery',
            'categories' => [[
                'title' => 'Six items',
                'images' => array_map(
                    fn (int $index): UploadedFile => investigationImageUpload('image-'.$index.'.png'),
                    range(1, 6),
                ),
            ]],
        ])
        ->assertRedirect();

    $caseFile = InvestigationCase::query()->with('categories.images')->firstOrFail();
    $firstImage = $caseFile->categories[0]->images[0];
    $response = $this->get(route('cases.show', $caseFile))
        ->assertOk()
        ->assertSee('+2')
        ->assertSee('data-gallery-extra', false)
        ->assertSee('data-vault-image-viewer', false)
        ->assertSee('data-vault-image-zoom-in', false)
        ->assertSee('data-vault-image-zoom-out', false)
        ->assertSee('data-vault-image-grayscale', false)
        ->assertSee('data-vault-image-stage', false);

    expect(substr_count($response->getContent(), 'data-gallery-extra'))->toBe(2);

    $download = $this->get(route('cases.images.download', ['caseFile' => $caseFile, 'image' => $firstImage]))
        ->assertOk();
    expect($download->headers->get('Content-Disposition'))->toBe('attachment; filename=image-1.png');
});

test('avatar models accept valid embedded GLB files and reject external asset references', function () {
    $settings = app(SettingService::class);
    $json = json_encode(['asset' => ['version' => '2.0'], 'scene' => 0], JSON_THROW_ON_ERROR);
    $json = str_pad($json, (int) (ceil(strlen($json) / 4) * 4), ' ');
    $glb = pack('V3', 0x46546C67, 2, 20 + strlen($json))
        .pack('V2', strlen($json), 0x4E4F534A)
        .$json;

    $this->actingAs(User::factory()->create())
        ->post(route('avatar-models.update', 'male'), [
            'model' => UploadedFile::fake()->createWithContent('male.glb', $glb),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $path = $settings->get('avatar_model_male');
    expect($path)->toStartWith('avatar-models/male-')
        ->and(Storage::disk('vault')->exists($path))->toBeTrue();

    foreach (['unknown', 'suspect', 'verified'] as $group) {
        $this->post(route('avatar-models.update', $group), [
            'model' => UploadedFile::fake()->createWithContent($group.'.glb', $glb),
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($settings->get('avatar_model_'.$group))->toStartWith('avatar-models/'.$group.'-');
    }

    $this->get(route('avatar-models.index'))
        ->assertOk()
        ->assertSee('Unknown')
        ->assertSee('Suspect')
        ->assertSee('Verified');

    $this->post(route('people.store'), [
        'gender' => 'Other',
        'avatar_group' => 'Suspect',
    ])->assertRedirect();
    $suspect = Person::query()->firstOrFail();
    expect($suspect->gender)->toBe('Other')
        ->and($suspect->avatar_group)->toBe('Suspect');
    $this->get(route('people.show', $suspect))
        ->assertOk()
        ->assertSee(route('avatar-models.stream', 'suspect'));

    $this->put(route('people.update', $suspect), [
        'gender' => 'Other',
        'avatar_group' => 'Verified',
    ])->assertRedirect();
    $suspect->refresh();
    expect($suspect->avatar_group)->toBe('Verified');
    $this->get(route('people.show', $suspect))
        ->assertOk()
        ->assertSee(route('avatar-models.stream', 'verified'));

    $externalJson = json_encode([
        'asset' => ['version' => '2.0'],
        'buffers' => [['uri' => 'https://example.invalid/external.bin']],
    ], JSON_THROW_ON_ERROR);
    $externalJson = str_pad($externalJson, (int) (ceil(strlen($externalJson) / 4) * 4), ' ');
    $unsafeGlb = pack('V3', 0x46546C67, 2, 20 + strlen($externalJson))
        .pack('V2', strlen($externalJson), 0x4E4F534A)
        .$externalJson;

    $this->from(route('avatar-models.index'))
        ->post(route('avatar-models.update', 'female'), [
            'model' => UploadedFile::fake()->createWithContent('female.glb', $unsafeGlb),
        ])
        ->assertRedirect(route('avatar-models.index'))
        ->assertSessionHasErrors('model');
});

test('person profile image deletion verifies ownership', function () {
    $person = Person::query()->create([
        'public_id' => 'USR-DEL1234',
        'name' => 'Unknown',
        'first_name' => 'Unknown',
        'last_name' => 'Unknown',
        'surname' => 'Unknown',
        'age' => 'Unknown',
        'date_of_birth' => 'Unknown',
        'marital_status' => 'Unknown',
        'spouse' => 'Unknown',
        'gender' => 'Unknown',
        'citizenship' => 'Unknown',
        'state' => 'Unknown',
        'blood_group' => 'Unknown',
        'genotype' => 'Unknown',
        'height' => 'Unknown',
        'flag' => 'Unknown',
        'occupation' => 'Unknown',
        'aliases' => 'Unknown',
        'last_known_location' => 'Unknown',
        'identifying_marks' => 'Unknown',
        'avatar_group' => 'Group',
        'statement' => 'Unknown',
    ]);
    $other = Person::query()->create([
        ...$person->getAttributes(),
        'id' => null,
        'public_id' => 'USR-OTH1234',
    ]);
    Storage::disk('vault')->put('people/other/image.jpg', 'image-bytes');
    $image = $other->images()->create(['path' => 'people/other/image.jpg', 'original_name' => 'image.jpg']);

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('people.images.destroy', ['person' => $person, 'image' => $image]))
        ->assertNotFound();

    expect(Storage::disk('vault')->exists('people/other/image.jpg'))->toBeTrue()
        ->and(PersonImage::query()->count())->toBe(1);
});
