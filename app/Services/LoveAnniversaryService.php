<?php

namespace App\Services;

use App\Models\KinshipEdge;
use App\Models\Member;
use App\Models\Reminder;
use App\Models\TemplatePackInstallation;
use App\Support\ShanghaiTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LoveAnniversaryService
{
    /** 常见恋爱里程碑天数 */
    public const DAY_MILESTONES = [7, 30, 50, 100, 200, 300, 365, 500, 666, 730, 1000, 1500, 2000];

    public function datingAt(TemplatePackInstallation $install): ?Carbon
    {
        $raw = $install->meta['dating_at'] ?? null;

        return $raw ? ShanghaiTime::parse($raw)?->startOfDay() : null;
    }

    public function daysTogether(TemplatePackInstallation $install): ?int
    {
        $start = $this->datingAt($install);
        if (! $start) {
            return null;
        }

        return (int) $start->diffInDays(now(ShanghaiTime::TZ)->startOfDay()) + 1;
    }

    /**
     * 汇总即将到来的纪念日（含恋爱里程碑、阶段日、生日、自定义）
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(TemplatePackInstallation $install, int $limit = 12): array
    {
        $items = [];
        $today = now(ShanghaiTime::TZ)->startOfDay();
        $datingAt = $this->datingAt($install);
        $days = $this->daysTogether($install);

        if ($datingAt && $days !== null) {
            $items[] = [
                'key' => 'dating_start',
                'kind' => 'origin',
                'title' => '在一起',
                'subtitle' => '第 '.$days.' 天',
                'date' => $datingAt->toDateString(),
                'days_together' => $days,
                'days_until' => 0,
                'is_today' => true,
            ];

            foreach (self::DAY_MILESTONES as $n) {
                if ($n <= $days) {
                    continue;
                }
                $target = $datingAt->copy()->addDays($n - 1);
                $items[] = [
                    'key' => 'milestone_'.$n,
                    'kind' => 'milestone',
                    'title' => $this->milestoneTitle($n),
                    'subtitle' => '恋爱第 '.$n.' 天',
                    'date' => $target->toDateString(),
                    'days_until' => (int) $today->diffInDays($target, false),
                    'is_today' => $target->isSameDay($today),
                    'milestone_days' => $n,
                ];
            }

            // 恋爱周年（按自然年）
            for ($y = 1; $y <= 20; $y++) {
                $target = $datingAt->copy()->addYears($y);
                if ($target->lt($today)) {
                    continue;
                }
                $items[] = [
                    'key' => 'dating_year_'.$y,
                    'kind' => 'yearly',
                    'title' => '恋爱 '.$y.' 周年',
                    'subtitle' => '在一起的日子',
                    'date' => $target->toDateString(),
                    'days_until' => (int) $today->diffInDays($target, false),
                    'is_today' => $target->isSameDay($today),
                ];
                if (count(array_filter($items, fn ($i) => ($i['kind'] ?? '') === 'yearly' && str_starts_with($i['key'], 'dating_year_'))) >= 3) {
                    break;
                }
            }
        }

        foreach (['engaged_at' => '订婚/备婚纪念日', 'married_at' => '结婚纪念日'] as $metaKey => $title) {
            $raw = $install->meta[$metaKey] ?? null;
            if (! $raw) {
                continue;
            }
            $origin = ShanghaiTime::parse($raw)?->startOfDay();
            if (! $origin) {
                continue;
            }
            $next = $this->nextYearlyOccurrence($origin, $today);
            $items[] = [
                'key' => $metaKey,
                'kind' => 'phase',
                'title' => $title,
                'subtitle' => $origin->format('Y-m-d').' 起',
                'date' => $next->toDateString(),
                'origin_date' => $origin->toDateString(),
                'days_until' => (int) $today->diffInDays($next, false),
                'is_today' => $next->isSameDay($today),
            ];
        }

        $partner = $install->partner;
        if ($partner?->birthday) {
            $bday = Carbon::parse($partner->birthday, ShanghaiTime::TZ)->startOfDay();
            $next = $this->nextYearlyOccurrence($bday, $today);
            $items[] = [
                'key' => 'partner_birthday',
                'kind' => 'birthday',
                'title' => ($partner->name ?: 'TA').'的生日',
                'subtitle' => '别忘了准备惊喜',
                'date' => $next->toDateString(),
                'days_until' => (int) $today->diffInDays($next, false),
                'is_today' => $next->isSameDay($today),
            ];
        }

        foreach ($install->meta['custom_anniversaries'] ?? [] as $row) {
            if (empty($row['date']) || empty($row['title'])) {
                continue;
            }
            $origin = ShanghaiTime::parse($row['date'])?->startOfDay();
            if (! $origin) {
                continue;
            }
            $yearly = $row['yearly'] ?? true;
            $next = $yearly ? $this->nextYearlyOccurrence($origin, $today) : $origin;
            if (! $yearly && $next->lt($today)) {
                continue;
            }
            $items[] = [
                'key' => 'custom_'.($row['id'] ?? md5($row['title'].$row['date'])),
                'kind' => 'custom',
                'title' => $row['title'],
                'subtitle' => $yearly ? '每年提醒' : '一次性',
                'date' => $next->toDateString(),
                'origin_date' => $origin->toDateString(),
                'days_until' => (int) $today->diffInDays($next, false),
                'is_today' => $next->isSameDay($today),
                'custom_id' => $row['id'] ?? null,
            ];
        }

        usort($items, function ($a, $b) {
            // 当前恋爱天数卡片置顶展示用，其余按临近排序
            if (($a['kind'] ?? '') === 'origin') {
                return -1;
            }
            if (($b['kind'] ?? '') === 'origin') {
                return 1;
            }

            return ($a['days_until'] ?? 9999) <=> ($b['days_until'] ?? 9999);
        });

        return array_slice($items, 0, $limit);
    }

    public function bindPartner(
        TemplatePackInstallation $install,
        Member $self,
        array $data,
        int $userId,
    ): TemplatePackInstallation {
        return DB::transaction(function () use ($install, $self, $data, $userId) {
            $partner = null;

            if (! empty($data['partner_member_id'])) {
                $partner = Member::where('workspace_id', $install->workspace_id)
                    ->where('id', $data['partner_member_id'])
                    ->firstOrFail();
                if ($partner->id === $self->id) {
                    throw new InvalidArgumentException('不能把自己绑成伴侣');
                }
                if ($partner->type === 'self') {
                    throw new InvalidArgumentException('不能绑定另一个「我」');
                }
                // 统一标记为 partner 类型（不改 child/elder 等若已是亲属角色——仅当 other/空时改）
                if (in_array($partner->type, ['other', 'partner'], true)) {
                    $partner->type = 'partner';
                }
                if (! empty($data['partner_name'])) {
                    $partner->name = $data['partner_name'];
                }
                if (! empty($data['partner_gender'])) {
                    $partner->gender = $data['partner_gender'];
                }
                if (! empty($data['partner_birthday'])) {
                    $partner->birthday = $data['partner_birthday'];
                }
                $partner->save();
            } else {
                if (empty($data['partner_name'])) {
                    throw new InvalidArgumentException('请填写伴侣姓名或选择已有成员');
                }
                $partner = Member::create([
                    'workspace_id' => $install->workspace_id,
                    'name' => $data['partner_name'],
                    'type' => 'partner',
                    'gender' => $data['partner_gender'] ?? null,
                    'birthday' => $data['partner_birthday'] ?? null,
                ]);
            }

            $this->ensureSpouseEdge($install->workspace_id, $self->id, $partner->id);

            $install->partner_member_id = $partner->id;
            $meta = $install->meta ?? [];
            if (! empty($data['dating_at'])) {
                $meta['dating_at'] = ShanghaiTime::format(ShanghaiTime::parse($data['dating_at']));
            }
            $meta['partner_bound_at'] = ShanghaiTime::format(now());
            $meta['partner_bound_by'] = $userId;
            $install->meta = $meta;
            $install->save();

            $this->syncCoreReminders($install->fresh(['partner']));

            return $install->fresh(['partner']);
        });
    }

    public function unbindPartner(TemplatePackInstallation $install): TemplatePackInstallation
    {
        $install->partner_member_id = null;
        $meta = $install->meta ?? [];
        unset($meta['partner_bound_at'], $meta['partner_bound_by']);
        $install->meta = $meta;
        $install->save();

        Reminder::where('workspace_id', $install->workspace_id)
            ->where('related_type', 'love')
            ->where('related_key', 'partner_birthday')
            ->delete();

        return $install->fresh(['partner']);
    }

    public function updateDates(TemplatePackInstallation $install, array $data): TemplatePackInstallation
    {
        $meta = $install->meta ?? [];
        foreach (['dating_at', 'engaged_at', 'married_at'] as $key) {
            if (array_key_exists($key, $data)) {
                if ($data[$key] === null || $data[$key] === '') {
                    unset($meta[$key]);
                } else {
                    $meta[$key] = ShanghaiTime::format(ShanghaiTime::parse($data[$key]));
                }
            }
        }
        $install->meta = $meta;
        $install->save();
        $this->syncCoreReminders($install->fresh(['partner']));

        return $install->fresh(['partner']);
    }

    public function addCustomAnniversary(TemplatePackInstallation $install, array $data): TemplatePackInstallation
    {
        $meta = $install->meta ?? [];
        $list = $meta['custom_anniversaries'] ?? [];
        $row = [
            'id' => (string) Str::uuid(),
            'title' => $data['title'],
            'date' => Carbon::parse($data['date'], ShanghaiTime::TZ)->toDateString(),
            'yearly' => $data['yearly'] ?? true,
            'remind_days_before' => $data['remind_days_before'] ?? 3,
        ];
        $list[] = $row;
        $meta['custom_anniversaries'] = $list;
        $install->meta = $meta;
        $install->save();
        $this->upsertCustomReminder($install, $row);

        return $install->fresh(['partner']);
    }

    public function removeCustomAnniversary(TemplatePackInstallation $install, string $id): TemplatePackInstallation
    {
        $meta = $install->meta ?? [];
        $list = collect($meta['custom_anniversaries'] ?? [])->reject(fn ($r) => ($r['id'] ?? '') === $id)->values()->all();
        $meta['custom_anniversaries'] = $list;
        $install->meta = $meta;
        $install->save();

        Reminder::where('workspace_id', $install->workspace_id)
            ->where('related_type', 'love')
            ->where('related_key', 'custom_'.$id)
            ->delete();

        return $install->fresh(['partner']);
    }

    /** 为核心日期写入/更新提醒（每年） */
    public function syncCoreReminders(TemplatePackInstallation $install): void
    {
        $workspaceId = $install->workspace_id;
        $partnerId = $install->partner_member_id;

        $map = [];
        if ($dating = $this->datingAt($install)) {
            $map['dating_at'] = ['title' => '恋爱纪念日', 'date' => $dating];
        }
        if (! empty($install->meta['engaged_at'])) {
            $d = ShanghaiTime::parse($install->meta['engaged_at']);
            if ($d) {
                $map['engaged_at'] = ['title' => '备婚/订婚纪念日', 'date' => $d];
            }
        }
        if (! empty($install->meta['married_at'])) {
            $d = ShanghaiTime::parse($install->meta['married_at']);
            if ($d) {
                $map['married_at'] = ['title' => '结婚纪念日', 'date' => $d];
            }
        }
        if ($install->partner?->birthday) {
            $map['partner_birthday'] = [
                'title' => ($install->partner->name ?: 'TA').'的生日',
                'date' => Carbon::parse($install->partner->birthday, ShanghaiTime::TZ),
            ];
        }

        foreach ($map as $key => $row) {
            $next = $this->nextYearlyOccurrence($row['date']->copy()->startOfDay(), now(ShanghaiTime::TZ)->startOfDay());
            $existing = Reminder::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->where('related_type', 'love')
                ->where('related_key', $key)
                ->first();

            $payload = [
                'workspace_id' => $workspaceId,
                'member_id' => $partnerId,
                'title' => $row['title'],
                'due_at' => $next->copy()->setTime(9, 0),
                'recurrence' => ['freq' => 'yearly', 'interval' => 1],
                'related_type' => 'love',
                'related_key' => $key,
                'status' => 'pending',
            ];

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->fill($payload)->save();
            } else {
                Reminder::create($payload);
            }
        }

        foreach ($install->meta['custom_anniversaries'] ?? [] as $custom) {
            $this->upsertCustomReminder($install, $custom);
        }
    }

    protected function upsertCustomReminder(TemplatePackInstallation $install, array $row): void
    {
        if (empty($row['id']) || empty($row['date']) || empty($row['title'])) {
            return;
        }
        $origin = ShanghaiTime::parse($row['date'])?->startOfDay();
        if (! $origin) {
            return;
        }
        $yearly = $row['yearly'] ?? true;
        $next = $yearly
            ? $this->nextYearlyOccurrence($origin, now(ShanghaiTime::TZ)->startOfDay())
            : $origin;
        $before = (int) ($row['remind_days_before'] ?? 3);
        $due = $next->copy()->subDays(max(0, $before))->setTime(9, 0);
        if ($due->lt(now(ShanghaiTime::TZ)) && $yearly) {
            $due = $this->nextYearlyOccurrence($origin, now(ShanghaiTime::TZ)->startOfDay())
                ->subDays(max(0, $before))
                ->setTime(9, 0);
        }

        $key = 'custom_'.$row['id'];
        $existing = Reminder::withTrashed()
            ->where('workspace_id', $install->workspace_id)
            ->where('related_type', 'love')
            ->where('related_key', $key)
            ->first();

        $payload = [
            'workspace_id' => $install->workspace_id,
            'member_id' => $install->partner_member_id,
            'title' => $row['title'].($before > 0 ? '（提前'.$before.'天）' : ''),
            'due_at' => $due,
            'recurrence' => $yearly ? ['freq' => 'yearly', 'interval' => 1] : null,
            'related_type' => 'love',
            'related_key' => $key,
            'status' => 'pending',
        ];

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->fill($payload)->save();
        } else {
            Reminder::create($payload);
        }
    }

    protected function ensureSpouseEdge(string $workspaceId, string $selfId, string $partnerId): void
    {
        $edge = KinshipEdge::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('relation', 'spouse')
            ->where(function ($q) use ($selfId, $partnerId) {
                $q->where(function ($q2) use ($selfId, $partnerId) {
                    $q2->where('from_member_id', $selfId)->where('to_member_id', $partnerId);
                })->orWhere(function ($q2) use ($selfId, $partnerId) {
                    $q2->where('from_member_id', $partnerId)->where('to_member_id', $selfId);
                });
            })
            ->first();

        if ($edge) {
            if ($edge->trashed()) {
                $edge->restore();
            }

            return;
        }

        KinshipEdge::create([
            'workspace_id' => $workspaceId,
            'from_member_id' => $selfId,
            'to_member_id' => $partnerId,
            'relation' => 'spouse',
        ]);
    }

    protected function nextYearlyOccurrence(Carbon $origin, Carbon $today): Carbon
    {
        $next = $origin->copy()->year($today->year);
        // 处理 2/29
        if ($origin->month === 2 && $origin->day === 29 && ! $next->isLeapYear()) {
            $next = Carbon::create($today->year, 2, 28, 0, 0, 0, ShanghaiTime::TZ);
        } else {
            $next->month($origin->month)->day($origin->day)->startOfDay();
        }
        if ($next->lt($today)) {
            $next->addYear();
            if ($origin->month === 2 && $origin->day === 29 && ! $next->isLeapYear()) {
                $next = Carbon::create($next->year, 2, 28, 0, 0, 0, ShanghaiTime::TZ)->startOfDay();
            }
        }

        return $next;
    }

    protected function milestoneTitle(int $n): string
    {
        return match ($n) {
            7 => '恋爱满一周',
            30 => '恋爱满月',
            365 => '恋爱 1 周年',
            730 => '恋爱 2 周年',
            default => '恋爱第 '.$n.' 天',
        };
    }
}
