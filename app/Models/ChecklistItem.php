<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'checklist_id',
        'workspace_id',
        'title',
        'dose_no',
        'dose_total',
        'is_free',
        'age_months',
        'recommended_on',
        'status',
        'done_at',
        'source_record_id',
        'sort',
        'meta',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_free' => 'boolean',
            'recommended_on' => 'date',
            'done_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }
}
