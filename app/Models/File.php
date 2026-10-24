<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class File extends Model
{
    protected $fillable = [
        'uuid',
        'original_name',
        'stored_path',
        'extension',
        'mime_type',
        'category',
        'size',
        'checksum',
        'is_editable',
        'version',
        'source_ip',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_editable' => 'boolean',
            'version' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget('dashboard.archive-metrics');
            Cache::forget('dashboard.archive-years');
            Cache::forget('dashboard.archive-extensions');
        });
        static::deleted(function (): void {
            Cache::forget('dashboard.archive-metrics');
            Cache::forget('dashboard.archive-years');
            Cache::forget('dashboard.archive-extensions');
        });
    }
}
