<?php

namespace App\Services;

use App\Models\LifeStageDef;
use App\Models\Member;
use App\Models\Stage;
use App\Models\TemplatePack;
use App\Models\TemplatePackInstallation;
use App\Support\ShanghaiTime;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LovePackService
{
    public const PHASE_DATING = 'dating';

    public const PHASE_ENGAGED = 'engaged';

    public const PHASE_MARRIED = 'married';

    public function ensureInstallation(string $workspaceId, int $userId): TemplatePackInstallation
    {
        TemplatePack::where('key', 'love')->firstOrFail();

        $row = TemplatePackInstallation::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('pack_key', 'love')
            ->first();

        if ($row) {
            if ($row->trashed()) {
                $row->restore();
                $row->deleted_by = null;
                $row->installed_at = now();
                $row->installed_by = $userId;
            }
            if (! $row->phase) {
                $row->phase = self::PHASE_DATING;
            }
            $row->save();

            return $row->fresh(['partner']);
        }

        return TemplatePackInstallation::create([
            'workspace_id' => $workspaceId,
            'pack_key' => 'love',
            'phase' => self::PHASE_DATING,
            'installed_at' => now(),
            'installed_by' => $userId,
        ]);
    }

    public function profile(TemplatePackInstallation $install): array
    {
        $pack = TemplatePack::find('love');
        $pres = $install->resolvePresentation($pack);
        $themes = config('workspace.colors.love_themes', []);
        $phase = $pres['phase'];
        $anniv = app(LoveAnniversaryService::class);
        $datingAt = $anniv->datingAt($install);

        return [
            'pack_key' => 'love',
            'phase' => $phase,
            'title' => $pres['title'],
            'subtitle' => $pres['subtitle'],
            'color' => $pres['color'],
            'color_soft' => $pres['color_soft'],
            'partner_member_id' => $pres['partner_member_id'],
            'partner' => $install->partner ? [
                'id' => $install->partner->id,
                'name' => $install->partner->name,
                'type' => $install->partner->type,
                'gender' => $install->partner->gender,
                'birthday' => optional($install->partner->birthday)?->toDateString(),
            ] : null,
            'dating_at' => $datingAt ? $datingAt->toDateString() : null,
            'days_together' => $anniv->daysTogether($install),
            'engaged_at' => $this->metaDate($install, 'engaged_at'),
            'married_at' => $this->metaDate($install, 'married_at'),
            'custom_anniversaries' => $install->meta['custom_anniversaries'] ?? [],
            'phase_changed_at' => ShanghaiTime::format($install->phase_changed_at),
            'themes' => $themes,
            'can_engage' => $phase === self::PHASE_DATING,
            'can_marry' => $phase !== self::PHASE_MARRIED,
            'can_upgrade' => $phase !== self::PHASE_MARRIED,
            'next_phases' => $this->nextPhases($phase),
        ];
    }

    /** @return list<array{key: string, title: string, life_stage: string}> */
    public function nextPhases(?string $phase): array
    {
        return match ($phase) {
            self::PHASE_DATING => [
                ['key' => self::PHASE_ENGAGED, 'title' => '备婚中', 'life_stage' => 'engaged'],
                ['key' => self::PHASE_MARRIED, 'title' => '婚姻中', 'life_stage' => 'married'],
            ],
            self::PHASE_ENGAGED => [
                ['key' => self::PHASE_MARRIED, 'title' => '婚姻中', 'life_stage' => 'married'],
            ],
            default => [],
        };
    }

    public function transitionPhase(
        TemplatePackInstallation $install,
        string $toPhase,
        array $data,
        int $userId,
        ?Member $selfMember = null,
    ): TemplatePackInstallation {
        $from = $install->phase ?: self::PHASE_DATING;
        $allowed = collect($this->nextPhases($from))->pluck('key')->all();

        if (! in_array($toPhase, $allowed, true)) {
            throw new InvalidArgumentException(
                $from === self::PHASE_MARRIED
                    ? '已经是婚姻模块'
                    : "当前阶段无法切换到「{$toPhase}」"
            );
        }

        return DB::transaction(function () use ($install, $toPhase, $data, $userId, $selfMember) {
            $defaultTheme = $toPhase === self::PHASE_ENGAGED ? 'champagne' : 'walnut';
            $theme = $this->resolveTheme($data['theme_key'] ?? $defaultTheme, $toPhase);

            $defaults = [
                self::PHASE_ENGAGED => [
                    'title' => '备婚日常',
                    'life_stage' => 'engaged',
                    'life_title' => '备婚中',
                    'meta_at' => 'engaged_at',
                ],
                self::PHASE_MARRIED => [
                    'title' => '婚姻日常',
                    'life_stage' => 'married',
                    'life_title' => '婚姻中',
                    'meta_at' => 'married_at',
                ],
            ][$toPhase];

            $changedAt = ! empty($data['changed_at'])
                ? ShanghaiTime::parse($data['changed_at'])
                : (! empty($data['married_at']) ? ShanghaiTime::parse($data['married_at']) : now());

            $install->phase = $toPhase;
            $install->display_title = $data['display_title'] ?? $defaults['title'];
            $install->color = $data['color'] ?? $theme['primary'];
            $install->color_soft = $data['color_soft'] ?? $theme['soft'];
            $install->phase_changed_at = $changedAt;
            if (! empty($data['partner_member_id'])) {
                $install->partner_member_id = $data['partner_member_id'];
            }

            $meta = $install->meta ?? [];
            $meta[$defaults['meta_at']] = ShanghaiTime::format($changedAt);
            $meta['last_transition'] = [
                'to' => $toPhase,
                'by' => $userId,
                'at' => ShanghaiTime::format(now()),
            ];
            $install->meta = $meta;
            $install->save();

            if ($selfMember && LifeStageDef::where('key', $defaults['life_stage'])->exists()) {
                Stage::where('member_id', $selfMember->id)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => $changedAt]);

                Stage::create([
                    'workspace_id' => $install->workspace_id,
                    'member_id' => $selfMember->id,
                    'kind' => $defaults['life_stage'],
                    'title' => $defaults['life_title'],
                    'started_at' => $changedAt,
                ]);
            }

            app(LoveAnniversaryService::class)->syncCoreReminders($install->fresh(['partner']));

            return $install->fresh(['partner']);
        });
    }

    /** @deprecated 使用 transitionPhase */
    public function upgradeToMarriage(
        TemplatePackInstallation $install,
        array $data,
        int $userId,
        ?Member $selfMember = null,
    ): TemplatePackInstallation {
        return $this->transitionPhase($install, self::PHASE_MARRIED, $data, $userId, $selfMember);
    }

    public function updateTheme(TemplatePackInstallation $install, array $data): TemplatePackInstallation
    {
        if (! empty($data['theme_key'])) {
            $phase = $install->phase ?: self::PHASE_DATING;
            $theme = $this->resolveTheme($data['theme_key'], $phase);
            $install->color = $theme['primary'];
            $install->color_soft = $theme['soft'];
        }
        if (! empty($data['color'])) {
            $install->color = $data['color'];
        }
        if (! empty($data['color_soft'])) {
            $install->color_soft = $data['color_soft'];
        }
        if (array_key_exists('display_title', $data)) {
            $install->display_title = $data['display_title'];
        }
        if (array_key_exists('partner_member_id', $data)) {
            $install->partner_member_id = $data['partner_member_id'];
        }
        $install->save();

        return $install->fresh(['partner']);
    }

    protected function resolveTheme(string $key, string $phase): array
    {
        $themes = collect(config('workspace.colors.love_themes', []));
        $found = $themes->first(fn ($t) => ($t['key'] ?? '') === $key);
        if ($found) {
            return $found;
        }

        $fallback = match ($phase) {
            self::PHASE_MARRIED => config('workspace.colors.packs.marriage'),
            self::PHASE_ENGAGED => config('workspace.colors.packs.engaged'),
            default => config('workspace.colors.packs.love'),
        };

        return [
            'primary' => $fallback['primary'],
            'soft' => $fallback['soft'],
        ];
    }

    protected function metaDate(TemplatePackInstallation $install, string $key): ?string
    {
        $raw = $install->meta[$key] ?? null;
        if (! $raw) {
            return null;
        }

        return ShanghaiTime::parse($raw)?->toDateString();
    }
}
