<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $fillable = [
        'public_id',
        'name',
        'first_name',
        'last_name',
        'surname',
        'age',
        'date_of_birth',
        'marital_status',
        'spouse',
        'gender',
        'citizenship',
        'state',
        'blood_group',
        'genotype',
        'height',
        'flag',
        'occupation',
        'aliases',
        'last_known_location',
        'identifying_marks',
        'avatar_group',
        'main_image_path',
        'statement',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(PersonImage::class);
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(InvestigationCase::class, 'case_person', 'person_id', 'case_file_id')->withTimestamps();
    }
}
