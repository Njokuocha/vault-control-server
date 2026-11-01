<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAnalysisRecentAccess extends Model
{
    protected $fillable = [
        'case_file_id',
        'user_id',
        'admin_id',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return ['last_accessed_at' => 'datetime'];
    }

    public function caseFile(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'case_file_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
