<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LifeStageDef extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'title',
        'subtitle',
        'primary_pack',
        'pack_keys',
        'sort',
        'is_core',
    ];

    protected function casts(): array
    {
        return [
            'pack_keys' => 'array',
            'is_core' => 'boolean',
        ];
    }
}
