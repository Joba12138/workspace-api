<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'linked_user_id',
        'name',
        'nickname',
        'type',
        'gender',
        'birthday',
        'born_at',
        'avatar_url',
        'meta',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'born_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
