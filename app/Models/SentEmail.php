<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentEmail extends Model
{
    protected $fillable = [
        'admin_id',
        'related_email_id',
        'message_kind',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'from_address',
        'from_name',
        'subject',
        'body_html',
        'status',
        'failure_reason',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'to_recipients' => 'encrypted:array',
            'cc_recipients' => 'encrypted:array',
            'bcc_recipients' => 'encrypted:array',
            'body_html' => 'encrypted',
            'sent_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SentEmailAttachment::class);
    }

    public function relatedEmail(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_email_id');
    }
}
