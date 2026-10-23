<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
