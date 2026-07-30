<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

trait SoftDeletesWithActor
{
    use SoftDeletes;

    public static function bootSoftDeletesWithActor(): void
    {
        static::deleting(function ($model) {
            if ($model->isForceDeleting()) {
                return;
            }
            if (auth()->check() && $model->isFillable('deleted_by')) {
                $model->deleted_by = auth()->id();
                $model->saveQuietly();
            }
        });
    }
}
