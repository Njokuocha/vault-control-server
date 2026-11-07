<?php

namespace App\Models;

use App\Support\CaseAnalysisCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CaseAnalysisNote extends Model
{
    protected $fillable = [
        'case_file_id',
        'author_type',
        'author_id',
        'author_name',
        'body',
        'version',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    protected static function booted(): void
    {
        $invalidate = static function (CaseAnalysisNote $note): void {
            app(CaseAnalysisCache::class)->invalidate((int) $note->case_file_id);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function caseFile(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'case_file_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
