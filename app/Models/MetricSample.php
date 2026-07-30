<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSample extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'member_id',
        'metric_key',
        'value',
        'unit',
        'measured_at',
        'source_record_id',
        'created_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'measured_at' => 'datetime',
        ];
    }

    public function metricDef(): BelongsTo
    {
        return $this->belongsTo(MetricDef::class, 'metric_key', 'key');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
