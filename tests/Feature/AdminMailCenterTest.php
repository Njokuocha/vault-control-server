<?php

use App\Livewire\Admin\MailCenter;
use App\Mail\OutgoingAgencyMail;
use App\Models\Admin;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'smtp',
        'mail.from.address' => 'vault@example.test',
        'mail.from.name' => 'Vault Agency',
    ]);
    Storage::fake('vault');
    Mail::fake();
});

test('administrator can send formatted email with to cc bcc and private attachments', function () {
    $admin = Admin::query()->create([
        'name' => 'Mail Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(MailCenter::class)
        ->set('toRecipients', 'recipient@example.test; second@example.test')
        ->set('ccRecipients', 'copy@example.test')
        ->set('bccRecipients', 'private@example.test')
        ->set('subject', 'Operational notice')
        ->set('bodyHtml', '<p>Secure <strong>formatted</strong> note<script>alert(1)</script><a href="javascript:alert(1)">unsafe link</a></p>')
        ->set('uploads', [UploadedFile::fake()->createWithContent('brief.txt', 'Private attachment')])
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Message sent')
        ->assertSee('Operational notice');

    $email = SentEmail::query()->with('attachments')->firstOrFail();
    expect($email->status)->toBe('sent')
        ->and($email->to_recipients)->toBe(['recipient@example.test', 'second@example.test'])
        ->and($email->cc_recipients)->toBe(['copy@example.test'])
        ->and($email->bcc_recipients)->toBe(['private@example.test'])
        ->and($email->body_html)->toContain('<strong>formatted</strong>')
        ->and($email->body_html)->not->toContain('<script')
        ->and($email->body_html)->not->toContain('javascript:')
        ->and($email->attachments)->toHaveCount(1);
    expect(DB::table('sent_emails')->where('id', $email->id)->value('body_html'))
        ->not->toContain('formatted')
        ->and(DB::table('sent_emails')->where('id', $email->id)->value('to_recipients'))
        ->not->toContain('recipient@example.test');
    Storage::disk('vault')->assertExists($email->attachments->first()->stored_path);
    Mail::assertSent(OutgoingAgencyMail::class, fn (OutgoingAgencyMail $message): bool => $message->email->id === $email->id);
});

test('non-delivery mail transports fail closed and retain a failed transmission record', function () {
    config(['mail.default' => 'log']);
    $admin = Admin::query()->create([
        'name' => 'Mail Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(MailCenter::class)
        ->set('toRecipients', 'recipient@example.test')
        ->set('subject', 'Transport check')
        ->set('bodyHtml', '<p>Do not log this message.</p>')
        ->call('send')
        ->assertDispatched('alert', type: 'error');

    expect(SentEmail::query()->firstOrFail()->status)->toBe('failed');
    Mail::assertNothingSent();
});

test('sent messages can be reviewed forwarded resent and deleted with confirmation', function () {
    $admin = Admin::query()->create([
        'name' => 'Mail Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);
    $component = Livewire::actingAs($admin, 'admin')->test(MailCenter::class)
        ->set('toRecipients', 'original@example.test')
        ->set('subject', 'Case update')
        ->set('bodyHtml', '<p>Review this report.</p>')
        ->set('uploads', [UploadedFile::fake()->createWithContent('case-note.txt', 'Private note')])
        ->call('send')
        ->assertHasNoErrors();
    $original = SentEmail::query()->with('attachments')->firstOrFail();

    $component->call('closeDeliveryNotice')
        ->call('requestForward', $original->id)
        ->set('forwardTo', 'forwarded@example.test')
        ->set('forwardCc', 'reviewer@example.test')
        ->call('sendForward')
        ->assertHasNoErrors()
        ->assertSee('Message sent');
    $forward = SentEmail::query()->where('message_kind', 'forward')->with('attachments')->firstOrFail();

    expect($forward->to_recipients)->toBe(['forwarded@example.test'])
        ->and($forward->cc_recipients)->toBe(['reviewer@example.test'])
        ->and($forward->related_email_id)->toBe($original->id)
        ->and($forward->attachments)->toHaveCount(1)
        ->and($forward->subject)->toStartWith('Fwd:');
    Storage::disk('vault')->assertExists($forward->attachments->first()->stored_path);

    $component->call('closeDeliveryNotice')
        ->call('requestResend', $original->id)
        ->assertSee('Resend this email?')
        ->call('confirmResend')
        ->assertSee('Message sent');
    $resent = SentEmail::query()->where('message_kind', 'resend')->with('attachments')->firstOrFail();
    expect($resent->to_recipients)->toBe(['original@example.test'])
        ->and($resent->related_email_id)->toBe($original->id)
        ->and($resent->attachments)->toHaveCount(1);

    $component->call('closeDeliveryNotice')
        ->call('openEmail', $original->id)
        ->assertSee('Review this report.')
        ->call('requestDelete', $original->id)
        ->assertSee('Delete this sent email?')
        ->call('confirmDelete')
        ->assertHasNoErrors();

    expect(SentEmail::query()->find($original->id))->toBeNull();
    Storage::disk('vault')->assertMissing($original->attachments->first()->stored_path);
    Mail::assertSent(OutgoingAgencyMail::class, 3);
});

test('mail center routes are restricted to the administrator guard', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $this->actingAs($staff, 'web')
        ->get(route('admin.mail.index'))
        ->assertRedirect(route('login'));
});
