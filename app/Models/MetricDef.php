<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetricDef extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'title',
        'unit',
        'pack_key',
        'color',
        'sort',
    ];
}
