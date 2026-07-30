<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'member_id',
        'title',
        'due_at',
        'recurrence',
        'related_type',
        'related_key',
        'status',
        'created_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'recurrence' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
