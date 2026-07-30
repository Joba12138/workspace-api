<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KinshipEdge extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'from_member_id',
        'to_member_id',
        'relation',
        'deleted_by',
    ];

    public function fromMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'from_member_id');
    }

    public function toMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'to_member_id');
    }
}
