<?php

namespace App\Livewire\Admin;

use App\Exceptions\MailDeliveryException;
use App\Models\Admin;
use App\Models\SentEmail;
use App\Services\AdminEmailFormatter;
use App\Services\AdminMailService;
use App\Services\MailTransportPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class MailCenter extends Component
{
    use WithFileUploads, WithPagination;

    public string $activeView = 'compose';

    public string $search = '';

    public ?int $selectedEmailId = null;

    public string $toRecipients = '';

    public string $ccRecipients = '';

    public string $bccRecipients = '';

    public string $subject = '';

    public string $bodyHtml = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public ?int $deleteConfirmationId = null;

    public ?int $resendConfirmationId = null;

    public ?int $forwardEmailId = null;

    public string $forwardTo = '';

    public string $forwardCc = '';

    public ?int $deliveryNoticeId = null;

    public function showComposer(): void
    {
        $this->authorizeAdministrator();
        $this->activeView = 'compose';
    }

    public function showSent(): void
    {
        $this->authorizeAdministrator();
        $this->activeView = 'sent';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->authorizeAdministrator();
        $this->resetPage();
    }

    public function openEmail(int $emailId): void
    {
        $this->authorizeAdministrator();
        $email = SentEmail::query()->with('attachments')->findOrFail($emailId);
        $this->selectedEmailId = $email->id;
        $this->activeView = 'sent';
    }

    public function send(AdminMailService $mail): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        $recipients = [
            'to' => $this->parseRecipients($this->toRecipients),
            'cc' => $this->parseRecipients($this->ccRecipients),
            'bcc' => $this->parseRecipients($this->bccRecipients),
        ];
        $validated = Validator::make([
            ...$recipients,
            'subject' => $this->subject,
            'bodyHtml' => $this->bodyHtml,
            'uploads' => $this->uploads,
        ], [
            'to' => ['required', 'array', 'min:1', 'max:50'],
            'to.*' => ['required', 'email:rfc', 'max:254'],
            'cc' => ['array', 'max:50'],
            'cc.*' => ['required', 'email:rfc', 'max:254'],
            'bcc' => ['array', 'max:50'],
            'bcc.*' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'bodyHtml' => ['nullable', 'string', 'max:60000'],
            'uploads' => ['array', 'max:10'],
            'uploads.*' => ['file', 'max:25600'],
        ], ['subject.not_regex' => 'The subject cannot contain line breaks.'])->validate();

        $safeBody = app(AdminEmailFormatter::class)->sanitize((string) ($validated['bodyHtml'] ?? ''));
        $totalAttachmentBytes = array_sum(array_map(
            fn ($upload): int => (int) ($upload->getSize() ?? 0),
            $this->uploads,
        ));
        if ($totalAttachmentBytes > 25 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'uploads' => 'Email attachments may not exceed 25 MB combined.',
            ]);
        }
        if (trim(html_entity_decode(strip_tags($safeBody), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '' && $this->uploads === []) {
            throw ValidationException::withMessages([
                'bodyHtml' => 'Add a message or attach a file before sending.',
            ]);
        }

        try {
            $email = $mail->compose(
                $admin,
                $validated['to'],
                $validated['cc'],
                $validated['bcc'],
                $validated['subject'],
                $safeBody,
                $this->uploads,
            );
        } catch (MailDeliveryException $exception) {
            $this->selectedEmailId = $exception->emailId;
            $this->dispatch('alert', type: 'error', message: $exception->getMessage());
            $this->showSent();

            return;
        }

        $this->selectedEmailId = $email->id;
        $this->activeView = 'sent';
        $this->deliveryNoticeId = $email->id;
        $this->reset(['toRecipients', 'ccRecipients', 'bccRecipients', 'subject', 'bodyHtml', 'uploads']);
        $this->dispatch('admin-mail-editor-reset');
    }

    public function requestDelete(int $emailId): void
    {
        $this->authorizeAdministrator();
        SentEmail::query()->findOrFail($emailId);
        $this->deleteConfirmationId = $emailId;
    }

    public function cancelDelete(): void
    {
        $this->authorizeAdministrator();
        $this->deleteConfirmationId = null;
    }

    public function confirmDelete(AdminMailService $mail): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $email = SentEmail::query()->findOrFail($this->deleteConfirmationId);
        $mail->delete($admin, $email);
        $deletedId = $email->id;
        $this->deleteConfirmationId = null;
        if ($this->selectedEmailId === $deletedId) {
            $this->selectedEmailId = null;
        }
        $this->dispatch('alert', type: 'success', message: 'The sent email and its private attachments were deleted.');
    }

    public function requestResend(int $emailId): void
    {
        $this->authorizeAdministrator();
        SentEmail::query()->findOrFail($emailId);
        $this->resendConfirmationId = $emailId;
    }

    public function cancelResend(): void
    {
        $this->authorizeAdministrator();
        $this->resendConfirmationId = null;
    }

    public function confirmResend(AdminMailService $mail): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $source = SentEmail::query()->with('attachments')->findOrFail($this->resendConfirmationId);

        try {
            $email = $mail->resend($admin, $source);
        } catch (MailDeliveryException $exception) {
            $this->resendConfirmationId = null;
            $this->selectedEmailId = $exception->emailId;
            $this->dispatch('alert', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->selectedEmailId = $email->id;
        $this->resendConfirmationId = null;
        $this->deliveryNoticeId = $email->id;
    }

    public function requestForward(int $emailId): void
    {
        $this->authorizeAdministrator();
        SentEmail::query()->findOrFail($emailId);
        $this->forwardEmailId = $emailId;
        $this->forwardTo = '';
        $this->forwardCc = '';
        $this->resetErrorBag('forwardTo');
        $this->resetErrorBag('forwardCc');
    }

    public function cancelForward(): void
    {
        $this->authorizeAdministrator();
        $this->forwardEmailId = null;
        $this->forwardTo = '';
        $this->forwardCc = '';
        $this->resetErrorBag('forwardTo');
        $this->resetErrorBag('forwardCc');
    }

    public function sendForward(AdminMailService $mail): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $validated = Validator::make([
            'to' => $this->parseRecipients($this->forwardTo),
            'cc' => $this->parseRecipients($this->forwardCc),
        ], [
            'to' => ['required', 'array', 'min:1', 'max:50'],
            'to.*' => ['required', 'email:rfc', 'max:254'],
            'cc' => ['array', 'max:50'],
            'cc.*' => ['required', 'email:rfc', 'max:254'],
        ], [], ['to' => 'forward recipient', 'to.*' => 'forward recipient', 'cc.*' => 'CC recipient'])->validate();
        $source = SentEmail::query()->with('attachments')->findOrFail($this->forwardEmailId);

        try {
            $email = $mail->forward($admin, $source, $validated['to'], $validated['cc']);
        } catch (MailDeliveryException $exception) {
            $this->cancelForward();
            $this->selectedEmailId = $exception->emailId;
            $this->activeView = 'sent';
            $this->dispatch('alert', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->selectedEmailId = $email->id;
        $this->activeView = 'sent';
        $this->cancelForward();
        $this->deliveryNoticeId = $email->id;
    }

    public function closeDeliveryNotice(): void
    {
        $this->authorizeAdministrator();
        $this->deliveryNoticeId = null;
    }

    public function render(): View
    {
        $this->authorizeAdministrator();
        $emails = SentEmail::query()
            ->withCount('attachments')
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('subject', 'like', $term)
                        ->orWhere('from_address', 'like', $term);
                });
            })
            ->latest('id')
            ->paginate(12);
        $selectedEmail = $this->selectedEmailId === null
            ? null
            : SentEmail::query()->with('attachments')->find($this->selectedEmailId);

        return view('livewire.admin.mail-center', [
            'emails' => $emails,
            'selectedEmail' => $selectedEmail,
            'selectedBodyHtml' => $selectedEmail === null
                ? ''
                : app(AdminEmailFormatter::class)->sanitize($selectedEmail->body_html),
            'deleteTarget' => $this->deleteConfirmationId === null
                ? null
                : SentEmail::query()->find($this->deleteConfirmationId),
            'resendTarget' => $this->resendConfirmationId === null
                ? null
                : SentEmail::query()->find($this->resendConfirmationId),
            'forwardTarget' => $this->forwardEmailId === null
                ? null
                : SentEmail::query()->find($this->forwardEmailId),
            'deliveryNotice' => $this->deliveryNoticeId === null
                ? null
                : SentEmail::query()->find($this->deliveryNoticeId),
            'mailTransportCanDeliver' => app(MailTransportPolicy::class)->canDeliver(),
        ])->extends('layouts.app', [
            'page_title' => 'Agency Mail Center — '.config('app.name'),
            'page_label' => 'MAIL CENTER',
        ])->section('content');
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipients(string $input): array
    {
        $addresses = preg_split('/[,;\r\n]+/', $input) ?: [];
        $unique = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            if ($address !== '') {
                $unique[Str::lower($address)] = $address;
            }
        }

        return array_values($unique);
    }

    private function authorizeAdministrator(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
