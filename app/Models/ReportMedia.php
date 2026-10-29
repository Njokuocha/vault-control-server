<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMedia extends Model
{
    protected $fillable = [
        'report_id',
        'path',
        'original_name',
        'mime_type',
        'media_type',
        'size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
