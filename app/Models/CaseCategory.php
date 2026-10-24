<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseCategory extends Model
{
    protected $fillable = ['case_file_id', 'title', 'description', 'sort_order'];

    public function caseFile(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'case_file_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CaseImage::class);
    }
}
