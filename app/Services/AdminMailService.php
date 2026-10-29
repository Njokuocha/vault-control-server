<?php

namespace App\Services;

use App\Exceptions\MailDeliveryException;
use App\Mail\OutgoingAgencyMail;
use App\Models\Admin;
use App\Models\SentEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AdminMailService
{
    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, UploadedFile>  $uploads
     */
    public function compose(
        Admin $admin,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $bodyHtml,
        array $uploads,
    ): SentEmail {
        return $this->createAndDeliver($admin, [
            'message_kind' => 'original',
            'related_email_id' => null,
            'to_recipients' => $to,
            'cc_recipients' => $cc,
            'bcc_recipients' => $bcc,
            'subject' => trim($subject),
            'body_html' => app(AdminEmailFormatter::class)->sanitize($bodyHtml),
        ], $uploads);
    }

    public function resend(Admin $admin, SentEmail $source): SentEmail
    {
        abort_if($source->status === 'sending', 409, 'This message is already being transmitted.');

        return $this->createAndDeliver($admin, [
            'message_kind' => 'resend',
            'related_email_id' => $source->id,
            'to_recipients' => $source->to_recipients,
            'cc_recipients' => $source->cc_recipients ?? [],
            'bcc_recipients' => $source->bcc_recipients ?? [],
            'subject' => $source->subject,
            'body_html' => app(AdminEmailFormatter::class)->sanitize($source->body_html),
        ], [], $source);
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     */
    public function forward(Admin $admin, SentEmail $source, array $to, array $cc): SentEmail
    {
        $forwardSubject = Str::startsWith(Str::lower($source->subject), 'fwd:')
            ? $source->subject
            : 'Fwd: '.mb_substr($source->subject, 0, 250);
        $header = '<p><strong>Forwarded message</strong></p>'
            .'<p><strong>From:</strong> '.e($source->from_name ? $source->from_name.' &lt;'.$source->from_address.'&gt;' : $source->from_address).'<br>'
            .'<strong>Sent:</strong> '.e($source->sent_at?->toDayDateTimeString() ?? $source->created_at->toDayDateTimeString()).'<br>'
            .'<strong>Subject:</strong> '.e($source->subject).'</p><hr>';

        return $this->createAndDeliver($admin, [
            'message_kind' => 'forward',
            'related_email_id' => $source->id,
            'to_recipients' => $to,
            'cc_recipients' => $cc,
            'bcc_recipients' => [],
            'subject' => $forwardSubject,
            'body_html' => $header.app(AdminEmailFormatter::class)->sanitize($source->body_html),
        ], [], $source);
    }

    public function delete(Admin $admin, SentEmail $email): void
    {
        $paths = $email->attachments()->pluck('stored_path')->all();
        $emailId = $email->id;
        DB::transaction(fn () => $email->delete());

        if ($paths !== [] && ! Storage::disk('vault')->delete($paths)) {
            throw new \RuntimeException('The email was deleted, but one or more private attachments could not be removed.');
        }

        app(ActivityLogger::class)->record(
            'communications',
            'mail.deleted',
            'An administrator deleted an email from the sent register.',
            ['email_id' => $emailId],
            $admin,
        );
    }

    private function createAndDeliver(Admin $admin, array $attributes, array $uploads, ?SentEmail $copyAttachmentsFrom = null): SentEmail
    {
        $storedPaths = [];

        try {
            $attachmentRows = [];
            $folder = 'mail/sent/'.Str::uuid();
            foreach ($uploads as $upload) {
                $extension = preg_replace('/[^a-z0-9]/', '', Str::lower($upload->getClientOriginalExtension())) ?? '';
                $path = $upload->storeAs(
                    $folder,
                    (string) Str::uuid().($extension === '' ? '' : '.'.$extension),
                    'vault',
                );
                if (! is_string($path)) {
                    throw new \RuntimeException('An email attachment could not be stored on private storage.');
                }
                $storedPaths[] = $path;
                $originalName = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
                $originalName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $originalName) ?? '');
                $attachmentRows[] = [
                    'stored_path' => $path,
                    'original_name' => Str::limit($originalName === '' ? 'attachment' : $originalName, 255, ''),
                    'mime_type' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
                    'size' => (int) $upload->getSize(),
                ];
            }

            if ($copyAttachmentsFrom !== null) {
                foreach ($copyAttachmentsFrom->attachments as $attachment) {
                    $disk = Storage::disk('vault');
                    if (! $disk->exists($attachment->stored_path)) {
                        throw new \RuntimeException("The private attachment {$attachment->original_name} is no longer available.");
                    }

                    $extension = pathinfo($attachment->stored_path, PATHINFO_EXTENSION);
                    $path = $folder.'/'.Str::uuid().($extension === '' ? '' : '.'.$extension);
                    if (! $disk->copy($attachment->stored_path, $path)) {
                        throw new \RuntimeException("The private attachment {$attachment->original_name} could not be copied.");
                    }
                    $storedPaths[] = $path;
                    $attachmentRows[] = [
                        'stored_path' => $path,
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                    ];
                }
            }

            $email = DB::transaction(function () use ($admin, $attributes, $attachmentRows): SentEmail {
                $email = SentEmail::query()->create([
                    ...$attributes,
                    'admin_id' => $admin->id,
                    'from_address' => (string) config('mail.from.address'),
                    'from_name' => (string) config('mail.from.name'),
                    'status' => 'sending',
                ]);
                $email->attachments()->createMany($attachmentRows);

                return $email->load('attachments');
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('vault')->delete($storedPaths);
            }

            throw $exception;
        }

        return $this->deliver($email);
    }

    private function deliver(SentEmail $email): SentEmail
    {
        try {
            app(MailTransportPolicy::class)->assertCanDeliver();
            $pendingMail = Mail::to($email->to_recipients);
            if (($email->cc_recipients ?? []) !== []) {
                $pendingMail->cc($email->cc_recipients);
            }
            if (($email->bcc_recipients ?? []) !== []) {
                $pendingMail->bcc($email->bcc_recipients);
            }
            $pendingMail->send(new OutgoingAgencyMail($email));
        } catch (Throwable $exception) {
            $email->forceFill([
                'status' => 'failed',
                'failure_reason' => $exception instanceof MailDeliveryException
                    ? $exception->getMessage()
                    : 'The configured mail transport rejected the message.',
            ])->save();
            report($exception);

            throw new MailDeliveryException(
                $exception instanceof MailDeliveryException
                    ? $exception->getMessage()
                    : 'The configured mail transport rejected the message.',
                $exception,
                $email->id,
            );
        }

        $email->forceFill([
            'status' => 'sent',
            'failure_reason' => null,
            'sent_at' => now(),
        ])->save();

        app(ActivityLogger::class)->record(
            'communications',
            'mail.sent',
            'An administrator sent an email from the agency mail center.',
            [
                'email_id' => $email->id,
                'message_kind' => $email->message_kind,
                'recipient_count' => count($email->to_recipients) + count($email->cc_recipients ?? []) + count($email->bcc_recipients ?? []),
                'attachment_count' => $email->attachments->count(),
            ],
        );

        return $email;
    }
}
