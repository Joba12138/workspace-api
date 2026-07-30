<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplatePack extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'title',
        'subtitle',
        'color',
        'color_soft',
        'icon',
        'sort',
        'is_public',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_public' => 'boolean',
        ];
    }
}
