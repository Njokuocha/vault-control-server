<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseImage extends Model
{
    protected $fillable = ['case_category_id', 'path', 'original_name'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CaseCategory::class, 'case_category_id');
    }
}
