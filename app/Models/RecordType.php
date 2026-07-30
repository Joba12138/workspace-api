<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecordType extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'title',
        'pack_key',
        'icon',
        'color',
        'sort',
        'schema',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
