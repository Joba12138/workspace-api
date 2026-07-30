<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'disk',
        'bucket_name',
        'size',
        'module',
        'kind',
        'file_name',
        'file_path',
        'md5_key',
        'mime_type',
        'width',
        'height',
        'duration',
        'extension',
        'guard',
        'uploaded_by',
        'member_id',
        'attachable_type',
        'attachable_id',
        'captured_at',
        'day_age',
        'note',
        'meta',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'day_age' => 'integer',
            'captured_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        $store = str_starts_with($this->disk, 'alioss_')
            ? substr($this->disk, strlen('alioss_'))
            : 'default';

        if ($this->disk === 'public' || $this->disk === 'local') {
            return url('/storage/'.$this->file_path);
        }

        $protocol = config("alioss.stores.{$store}.ssl") ? 'https' : 'http';

        if (config("alioss.stores.{$store}.is_domain") && config("alioss.stores.{$store}.cdn_domain")) {
            return "{$protocol}://".config("alioss.stores.{$store}.cdn_domain").'/'.$this->file_path;
        }

        $bucket = config("alioss.stores.{$store}.bucket");
        $endpoint = config("alioss.stores.{$store}.endpoint");

        return "{$protocol}://{$bucket}.{$endpoint}/{$this->file_path}";
    }

    public function thumbUrl(?int $width = 400): string
    {
        $url = $this->url();
        if ($this->kind !== 'image' || $this->disk === 'public' || $this->disk === 'local') {
            return $url;
        }
        // 阿里云图片处理：等比缩放
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.'x-oss-process=image/resize,w_'.$width;
    }
}
