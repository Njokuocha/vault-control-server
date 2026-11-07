<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentEmailAttachment extends Model
{
    protected $fillable = [
        'sent_email_id',
        'stored_path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(SentEmail::class, 'sent_email_id');
    }
}
