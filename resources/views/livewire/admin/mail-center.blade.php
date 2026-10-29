<section class="admin-mail-page">
    <header class="mail-page-heading">
        <div>
            <span class="section-kicker">AGENCY COMMUNICATIONS · ADMINISTRATOR ACCESS</span>
            <h1>Mail <span>center</span></h1>
            <p>Compose, transmit, and review correspondence through the configured agency mail server.</p>
        </div>
        <div class="mail-transport-badge {{ $mailTransportCanDeliver ? 'is-ready' : 'is-unavailable' }}"><span></span><div><small>{{ $mailTransportCanDeliver ? 'OUTGOING TRANSPORT' : 'DELIVERY UNAVAILABLE' }}</small><strong>{{ strtoupper(config('mail.default')) }}</strong></div></div>
    </header>

    <div class="mail-view-switch" role="tablist" aria-label="Mail center views">
        <button type="button" class="{{ $activeView === 'compose' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $activeView === 'compose' ? 'true' : 'false' }}" wire:click="showComposer"><i class="ri ri-edit-2-line" aria-hidden="true"></i> COMPOSE MESSAGE</button>
        <button type="button" class="{{ $activeView === 'sent' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $activeView === 'sent' ? 'true' : 'false' }}" wire:click="showSent"><i class="ri ri-send-plane-line" aria-hidden="true"></i> SENT MAIL <span>{{ $emails->total() }}</span></button>
    </div>

    @if ($activeView === 'compose')
        <div class="mail-compose-layout">
            <form class="mail-compose-panel" wire:submit="send" data-admin-mail-composer>
                <div class="mail-panel-heading"><div><span class="section-kicker">NEW OUTGOING MESSAGE</span><h2>Compose correspondence</h2></div><i class="ri ri-mail-send-line" aria-hidden="true"></i></div>

                <div class="mail-recipient-grid">
                    <label class="mail-field mail-field-wide"><span>TO <em>REQUIRED</em></span><input type="text" wire:model="toRecipients" autocomplete="off" placeholder="recipient@agency.gov; colleague@example.org"><small>Separate addresses with commas, semicolons, or new lines.</small>@error('to')<b>{{ $message }}</b>@enderror @error('to.*')<b>{{ $message }}</b>@enderror</label>
                    <label class="mail-field"><span>CC</span><input type="text" wire:model="ccRecipients" autocomplete="off" placeholder="Optional copy recipients">@error('cc')<b>{{ $message }}</b>@enderror @error('cc.*')<b>{{ $message }}</b>@enderror</label>
                    <label class="mail-field"><span>BCC <small>PRIVATE COPY</small></span><input type="text" wire:model="bccRecipients" autocomplete="off" placeholder="Optional concealed recipients">@error('bcc')<b>{{ $message }}</b>@enderror @error('bcc.*')<b>{{ $message }}</b>@enderror</label>
                </div>

                <label class="mail-field mail-subject-field"><span>SUBJECT</span><input type="text" wire:model="subject" maxlength="255" placeholder="Enter a concise subject"><small>@error('subject'){{ $message }}@enderror</small></label>

                <div class="mail-editor-field">
                    <div class="mail-editor-label"><span>MESSAGE</span><small>FORMATTED HTML · UNSAFE CONTENT IS REMOVED BEFORE SENDING</small></div>
                    <div class="mail-editor-toolbar" role="toolbar" aria-label="Message formatting">
                        <button type="button" data-admin-mail-command="bold" title="Bold" aria-label="Bold"><strong>B</strong></button>
                        <button type="button" data-admin-mail-command="italic" title="Italic" aria-label="Italic"><em>I</em></button>
                        <button type="button" data-admin-mail-command="underline" title="Underline" aria-label="Underline"><u>U</u></button>
                        <span></span>
                        <button type="button" data-admin-mail-command="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list"><i class="ri ri-list-unordered"></i></button>
                        <button type="button" data-admin-mail-command="insertOrderedList" title="Numbered list" aria-label="Numbered list"><i class="ri ri-list-ordered"></i></button>
                        <button type="button" data-admin-mail-command="formatBlock" data-admin-mail-value="blockquote" title="Quote" aria-label="Quote"><i class="ri ri-double-quotes-l"></i></button>
                        <span></span>
                        <button type="button" data-admin-mail-command="removeFormat" title="Clear formatting" aria-label="Clear formatting"><i class="ri ri-format-clear"></i></button>
                    </div>
                    <div class="mail-rich-editor" data-admin-mail-editor wire:ignore contenteditable="true" role="textbox" aria-label="Email message" aria-multiline="true" spellcheck="true"></div>
                    <input type="hidden" wire:model="bodyHtml" value="{{ $bodyHtml }}" data-admin-mail-editor-state>
                    @error('bodyHtml')<small class="mail-field-error">{{ $message }}</small>@enderror
                </div>

                <div class="mail-attachment-field">
                    <label for="mail-attachments"><span><i class="ri ri-attachment-2" aria-hidden="true"></i> ATTACH FILES</span><small>UP TO 10 FILES · 25 MB COMBINED</small></label>
                    <input id="mail-attachments" type="file" wire:model="uploads" multiple>
                    @error('uploads')<small class="mail-field-error">{{ $message }}</small>@enderror
                    @error('uploads.*')<small class="mail-field-error">{{ $message }}</small>@enderror
                    @if (count($uploads) > 0)
                        <ul class="mail-staged-files">@foreach ($uploads as $upload)<li><i class="ri ri-file-line" aria-hidden="true"></i><span>{{ $upload->getClientOriginalName() }}</span><small>{{ number_format(($upload->getSize() ?? 0) / 1048576, 1) }} MB</small></li>@endforeach</ul>
                    @endif
                    <p wire:loading wire:target="uploads">SECURELY STAGING ATTACHMENTS…</p>
                </div>

                <footer class="mail-compose-footer"><span><i class="ri ri-lock-2-line" aria-hidden="true"></i> TRANSMITTED THROUGH THE CONFIGURED SERVER</span><button class="mail-primary-button" type="submit" wire:loading.attr="disabled" wire:target="send,uploads"><i class="ri ri-send-plane-fill" aria-hidden="true"></i><span wire:loading.remove wire:target="send">SEND MESSAGE</span><span wire:loading wire:target="send">TRANSMITTING…</span></button></footer>
            </form>

            <aside class="mail-compose-aside">
                <div class="mail-sidecard"><span class="section-kicker">MAIL OPERATIONS</span><div><i class="ri ri-shield-check-line" aria-hidden="true"></i><p><strong>Private archive</strong><small>Sent-message records and attachments are restricted to administrator access.</small></p></div><div><i class="ri ri-eye-off-line" aria-hidden="true"></i><p><strong>Discreet recipients</strong><small>BCC addresses are stored for the operator but hidden from other recipients.</small></p></div><div><i class="ri ri-history-line" aria-hidden="true"></i><p><strong>Transmission record</strong><small>Each send and resend is recorded independently in the sent-mail register.</small></p></div></div>
                <div class="mail-sidecard mail-sidecard--note"><span class="section-kicker">DELIVERY STATUS</span><p>A successful response confirms acceptance by the configured mail transport. Final inbox delivery depends on the recipient mail system.</p></div>
            </aside>
        </div>
    @else
        <div class="mail-sent-layout">
            <aside class="mail-list-panel">
                <div class="mail-list-heading"><div><span class="section-kicker">OUTGOING REGISTER</span><h2>Sent mail</h2></div><button type="button" wire:click="showComposer" aria-label="Compose a message"><i class="ri ri-add-line"></i></button></div>
                <label class="mail-search"><i class="ri ri-search-line" aria-hidden="true"></i><input type="search" wire:model.live.debounce.250ms="search" placeholder="Search subject"><kbd>⌕</kbd></label>
                <div class="mail-list" aria-label="Sent messages">
                    @forelse ($emails as $email)
                        <button type="button" class="mail-list-row {{ $selectedEmail?->id === $email->id ? 'is-selected' : '' }}" wire:click="openEmail({{ $email->id }})">
                            <span class="mail-list-row__status {{ $email->status === 'sent' ? 'is-sent' : ($email->status === 'failed' ? 'is-failed' : '') }}"></span>
                            <span class="mail-list-row__copy"><strong>{{ $email->subject }}</strong><small>TO {{ implode(', ', $email->to_recipients) }}</small><small>{{ $email->created_at->format('d M Y · H:i') }}</small></span>
                            @if ($email->attachments_count > 0)<i class="ri ri-attachment-2" title="{{ $email->attachments_count }} attachment(s)" aria-label="{{ $email->attachments_count }} attachment(s)"></i>@endif
                        </button>
                    @empty
                        <div class="mail-empty-register"><i class="ri ri-mail-open-line" aria-hidden="true"></i><strong>No sent messages</strong><span>Messages you transmit will appear here.</span></div>
                    @endforelse
                </div>
                <div class="mail-pagination">{{ $emails->links() }}</div>
            </aside>

            <article class="mail-message-panel">
                @if ($selectedEmail)
                    <header class="mail-message-heading">
                        <div><span class="section-kicker">{{ strtoupper($selectedEmail->message_kind) }} · {{ $selectedEmail->status === 'sent' ? 'TRANSMITTED' : strtoupper($selectedEmail->status) }}</span><h2>{{ $selectedEmail->subject }}</h2><small>{{ $selectedEmail->sent_at?->format('d M Y · H:i:s') ?? $selectedEmail->created_at->format('d M Y · H:i:s') }}</small></div>
                        <div class="mail-message-actions">
                            <button type="button" wire:click="requestResend({{ $selectedEmail->id }})" title="Resend" aria-label="Resend"><i class="ri ri-refresh-line"></i></button>
                            <button type="button" wire:click="requestForward({{ $selectedEmail->id }})" title="Forward" aria-label="Forward"><i class="ri ri-share-forward-line"></i></button>
                            <button type="button" class="is-danger" wire:click="requestDelete({{ $selectedEmail->id }})" title="Delete" aria-label="Delete"><i class="ri ri-delete-bin-line"></i></button>
                        </div>
                    </header>
                    @if ($selectedEmail->failure_reason)<div class="mail-delivery-failure"><i class="ri ri-error-warning-line"></i><span>{{ $selectedEmail->failure_reason }}</span></div>@endif
                    <dl class="mail-message-recipients">
                        <div><dt>FROM</dt><dd>{{ $selectedEmail->from_name ? $selectedEmail->from_name.' <'.$selectedEmail->from_address.'>' : $selectedEmail->from_address }}</dd></div>
                        <div><dt>TO</dt><dd>{{ implode(', ', $selectedEmail->to_recipients) }}</dd></div>
                        @if ($selectedEmail->cc_recipients)<div><dt>CC</dt><dd>{{ implode(', ', $selectedEmail->cc_recipients) }}</dd></div>@endif
                        @if ($selectedEmail->bcc_recipients)<div><dt>BCC</dt><dd>{{ implode(', ', $selectedEmail->bcc_recipients) }}</dd></div>@endif
                    </dl>
                    @if ($selectedEmail->attachments->isNotEmpty())
                        <div class="mail-message-attachments"><span class="section-kicker">ATTACHMENTS · {{ $selectedEmail->attachments->count() }}</span><div>@foreach ($selectedEmail->attachments as $attachment)<a href="{{ route('admin.mail.attachments.download', $attachment) }}"><i class="ri ri-file-download-line" aria-hidden="true"></i><span>{{ $attachment->original_name }}<small>{{ number_format($attachment->size / 1048576, 1) }} MB</small></span></a>@endforeach</div></div>
                    @endif
                    <div class="mail-message-body">{!! $selectedBodyHtml !!}</div>
                @else
                    <div class="mail-message-empty"><i class="ri ri-mail-check-line" aria-hidden="true"></i><span class="section-kicker">AGENCY CORRESPONDENCE</span><h2>Select a sent message</h2><p>Choose an entry from the register to review its recipients, body, and private attachments.</p></div>
                @endif
            </article>
        </div>
    @endif

    @if ($deleteTarget)
        <div class="mail-modal-backdrop" role="presentation"><section class="mail-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="mail-delete-title"><span class="section-kicker">PERMANENT ACTION</span><h2 id="mail-delete-title">Delete this sent email?</h2><p><strong>{{ $deleteTarget->subject }}</strong> and its privately stored attachments will be permanently removed from the sent register.</p><div><button class="mail-secondary-button" type="button" wire:click="cancelDelete">CANCEL</button><button class="mail-danger-button" type="button" wire:click="confirmDelete">DELETE MESSAGE</button></div></section></div>
    @endif

    @if ($resendTarget)
        <div class="mail-modal-backdrop" role="presentation"><section class="mail-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="mail-resend-title"><span class="section-kicker">REPEAT TRANSMISSION</span><h2 id="mail-resend-title">Resend this email?</h2><p><strong>{{ $resendTarget->subject }}</strong> will be sent again to the original To, CC, and BCC recipients with its attachments. A separate transmission record will be created.</p><div><button class="mail-secondary-button" type="button" wire:click="cancelResend">CANCEL</button><button class="mail-primary-button" type="button" wire:click="confirmResend">RESEND MESSAGE</button></div></section></div>
    @endif

    @if ($forwardTarget)
        <div class="mail-modal-backdrop" role="presentation" wire:click.self="cancelForward"><section class="mail-confirm-modal mail-forward-modal" role="dialog" aria-modal="true" aria-labelledby="mail-forward-title"><button class="mail-modal-close" type="button" wire:click="cancelForward" aria-label="Close forward dialog">×</button><span class="section-kicker">FORWARD CORRESPONDENCE</span><h2 id="mail-forward-title">Choose recipients</h2><p>The message and its attachments will be forwarded as a new outgoing email.</p><form wire:submit="sendForward"><label class="mail-field"><span>TO</span><input type="text" wire:model="forwardTo" autocomplete="off" placeholder="recipient@example.org">@error('to')<b>{{ $message }}</b>@enderror @error('to.*')<b>{{ $message }}</b>@enderror</label><label class="mail-field"><span>CC <small>OPTIONAL</small></span><input type="text" wire:model="forwardCc" autocomplete="off" placeholder="Copy recipients">@error('cc.*')<b>{{ $message }}</b>@enderror</label><div><button class="mail-secondary-button" type="button" wire:click="cancelForward">CANCEL</button><button class="mail-primary-button" type="submit" wire:loading.attr="disabled" wire:target="sendForward">FORWARD MESSAGE</button></div></form></section></div>
    @endif

    @if ($deliveryNotice)
        <div class="mail-modal-backdrop" role="presentation"><section class="mail-confirm-modal mail-delivery-modal" role="dialog" aria-modal="true" aria-labelledby="mail-delivery-title"><div class="mail-delivery-emblem"><i class="ri ri-check-line" aria-hidden="true"></i></div><span class="section-kicker">TRANSMISSION ACCEPTED</span><h2 id="mail-delivery-title">Message sent</h2><p><strong>{{ $deliveryNotice->subject }}</strong> was accepted by the configured mail transport and added to the sent register.</p><button class="mail-primary-button" type="button" wire:click="closeDeliveryNotice">CONTINUE TO SENT MAIL</button></section></div>
    @endif
</section>
