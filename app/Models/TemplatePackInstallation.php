<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplatePackInstallation extends Model
{
    use HasUuids, SoftDeletesWithActor;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'pack_key',
        'phase',
        'display_title',
        'color',
        'color_soft',
        'partner_member_id',
        'phase_changed_at',
        'meta',
        'installed_at',
        'installed_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'phase_changed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(TemplatePack::class, 'pack_key', 'key');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'partner_member_id');
    }

    /** 有效展示信息（恋爱→备婚→婚姻会换标题与默认色） */
    public function resolvePresentation(?TemplatePack $pack = null): array
    {
        $pack = $pack ?: $this->pack;
        $phase = $this->phase ?: ($this->pack_key === 'love' ? 'dating' : null);

        $defaults = [
            'dating' => config('workspace.colors.packs.love'),
            'engaged' => config('workspace.colors.packs.engaged'),
            'married' => config('workspace.colors.packs.marriage'),
        ];

        $fallback = $defaults[$phase] ?? [
            'primary' => $pack?->color,
            'soft' => $pack?->color_soft,
            'title' => $pack?->title,
        ];

        $title = $this->display_title ?: match ($phase) {
            'married' => '婚姻日常',
            'engaged' => '备婚日常',
            default => $pack?->title ?? '恋爱日常',
        };

        $subtitle = match ($phase) {
            'married' => '纪念日、共同生活与陪伴',
            'engaged' => '婚礼筹备、清单与倒计时',
            default => $pack?->subtitle ?? '约会、纪念日与甜蜜瞬间',
        };

        return [
            'phase' => $phase,
            'title' => $title,
            'subtitle' => $subtitle,
            'color' => $this->color ?: ($fallback['primary'] ?? $pack?->color),
            'color_soft' => $this->color_soft ?: ($fallback['soft'] ?? $pack?->color_soft),
            'partner_member_id' => $this->partner_member_id,
            'phase_changed_at' => $this->phase_changed_at,
        ];
    }
}
