<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonImage extends Model
{
    protected $fillable = ['person_id', 'path', 'original_name'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
