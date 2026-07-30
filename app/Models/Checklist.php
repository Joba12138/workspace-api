<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checklist extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'member_id',
        'key',
        'title',
        'pack_key',
        'meta',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort')->orderBy('age_months');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
