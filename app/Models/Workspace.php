<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'owner_id',
        'deleted_by',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function packInstallations(): HasMany
    {
        return $this->hasMany(TemplatePackInstallation::class);
    }
}
