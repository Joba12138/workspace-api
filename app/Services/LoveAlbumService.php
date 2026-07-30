<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Record;
use App\Models\TemplatePackInstallation;
use App\Support\ShanghaiTime;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LoveAlbumService
{
    public function __construct(protected AttachmentService $attachments) {}

    /**
     * @return array<string, mixed>
     */
    public function album(TemplatePackInstallation $install, string $groupBy = 'date'): array
    {
        $items = Attachment::where('workspace_id', $install->workspace_id)
            ->where('module', 'love')
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at')
            ->get();

        $mapped = $items->map(fn (Attachment $a) => $this->attachments->toArray($a))->all();

        $groups = match ($groupBy) {
            'anniversary' => $this->groupByAnniversary($mapped, $install),
            'event' => $this->groupByEvent($mapped),
            default => $this->groupByDate($mapped),
        };

        $events = Record::with('recordType')
            ->where('workspace_id', $install->workspace_id)
            ->whereIn('type', [
                'date_night', 'anniversary', 'love_note', 'gift',
                'wedding_prep', 'wedding', 'daily_together', 'wishlist',
            ])
            ->orderByDesc('happened_at')
            ->limit(40)
            ->get()
            ->map(fn (Record $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_title' => $r->recordType?->title ?: $r->type,
                'title' => $this->recordTitle($r),
                'happened_at' => ShanghaiTime::format($r->happened_at),
                'happened_on' => optional($r->happened_at)?->timezone('Asia/Shanghai')->toDateString(),
            ]);

        $anniversaries = $this->anniversaryOptions($install);

        return [
            'total' => $items->count(),
            'image_count' => $items->where('kind', 'image')->count(),
            'video_count' => $items->where('kind', 'video')->count(),
            'group_by' => $groupBy,
            'groups' => $groups,
            'recent' => array_slice($mapped, 0, 30),
            'event_options' => $events,
            'anniversary_options' => $anniversaries,
        ];
    }

    /**
     * 绑定恋爱相册元信息（上传后或二次编辑）
     */
    public function bind(Attachment $attachment, TemplatePackInstallation $install, array $data): Attachment
    {
        if ($attachment->module !== 'love') {
            $attachment->module = 'love';
        }

        $meta = $attachment->meta ?? [];
        $meta['scope'] = 'love';
        $bindType = $data['bind_type'] ?? ($meta['bind_type'] ?? 'date');
        $meta['bind_type'] = $bindType;

        if (array_key_exists('note', $data)) {
            $attachment->note = $data['note'];
        }
        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $meta['title'] = $data['title'];
        }

        if ($bindType === 'event' && ! empty($data['record_id'])) {
            $record = Record::where('workspace_id', $install->workspace_id)
                ->where('id', $data['record_id'])
                ->firstOrFail();
            $attachment->attachable_type = Record::class;
            $attachment->attachable_id = $record->id;
            $attachment->captured_at = $record->happened_at ?: ($data['captured_at'] ? ShanghaiTime::parse($data['captured_at']) : $attachment->captured_at);
            $meta['record_id'] = $record->id;
            $meta['record_type'] = $record->type;
            $meta['title'] = $meta['title'] ?? $this->recordTitle($record);
            unset($meta['anniversary_id'], $meta['milestone_key']);
        } elseif ($bindType === 'anniversary') {
            $annivId = $data['anniversary_id'] ?? null;
            $opt = collect($this->anniversaryOptions($install))->firstWhere('id', $annivId);
            if (! $opt) {
                throw new \InvalidArgumentException('纪念日不存在');
            }
            $attachment->attachable_type = null;
            $attachment->attachable_id = null;
            $meta['anniversary_id'] = $opt['id'];
            $meta['title'] = $meta['title'] ?? $opt['title'];
            if (! empty($data['captured_at'])) {
                $attachment->captured_at = ShanghaiTime::parse($data['captured_at']);
            } elseif (! empty($opt['date'])) {
                $attachment->captured_at = ShanghaiTime::parse($opt['date']);
            }
            unset($meta['record_id'], $meta['record_type'], $meta['milestone_key']);
        } elseif ($bindType === 'milestone') {
            $key = $data['milestone_key'] ?? '';
            $meta['milestone_key'] = $key;
            $meta['title'] = $meta['title'] ?? ($data['title'] ?? $key);
            if (! empty($data['captured_at'])) {
                $attachment->captured_at = ShanghaiTime::parse($data['captured_at']);
            }
            $attachment->attachable_type = null;
            $attachment->attachable_id = null;
            unset($meta['record_id'], $meta['record_type'], $meta['anniversary_id']);
        } else {
            // date
            if (! empty($data['captured_at'])) {
                $attachment->captured_at = ShanghaiTime::parse($data['captured_at']);
            }
            $attachment->attachable_type = null;
            $attachment->attachable_id = null;
            unset($meta['record_id'], $meta['record_type'], $meta['anniversary_id'], $meta['milestone_key']);
            if (empty($meta['title']) && $attachment->captured_at) {
                $meta['title'] = $attachment->captured_at->timezone('Asia/Shanghai')->format('Y-m-d').' 的回忆';
            }
        }

        // 恋爱天数（相对 dating_at）
        $datingAt = app(LoveAnniversaryService::class)->datingAt($install);
        if ($datingAt && $attachment->captured_at) {
            $meta['love_day'] = (int) $datingAt->diffInDays(
                Carbon::parse($attachment->captured_at)->timezone('Asia/Shanghai')->startOfDay()
            ) + 1;
        }

        $attachment->meta = $meta;
        $attachment->save();

        return $attachment->fresh();
    }

    /** @return list<array<string, mixed>> */
    public function anniversaryOptions(TemplatePackInstallation $install): array
    {
        $opts = [];
        $dating = app(LoveAnniversaryService::class)->datingAt($install);
        if ($dating) {
            $opts[] = [
                'id' => 'dating_at',
                'title' => '在一起的日子',
                'date' => $dating->toDateString(),
                'kind' => 'origin',
            ];
        }
        foreach (['engaged_at' => '备婚/订婚日', 'married_at' => '结婚纪念日'] as $key => $title) {
            if (! empty($install->meta[$key])) {
                $d = ShanghaiTime::parse($install->meta[$key]);
                $opts[] = [
                    'id' => $key,
                    'title' => $title,
                    'date' => $d?->toDateString(),
                    'kind' => 'phase',
                ];
            }
        }
        foreach ($install->meta['custom_anniversaries'] ?? [] as $row) {
            if (empty($row['id'])) {
                continue;
            }
            $opts[] = [
                'id' => 'custom_'.$row['id'],
                'title' => $row['title'] ?? '自定义纪念日',
                'date' => $row['date'] ?? null,
                'kind' => 'custom',
                'custom_id' => $row['id'],
            ];
        }

        return $opts;
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function groupByDate(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $day = $item['captured_on']
                ?? (isset($item['captured_at']) ? substr((string) $item['captured_at'], 0, 10) : '未标注日期');
            if (! isset($groups[$day])) {
                $groups[$day] = [
                    'key' => $day,
                    'title' => $day === '未标注日期' ? $day : $day,
                    'subtitle' => $this->loveDayLabel($item),
                    'bind_type' => 'date',
                    'items' => [],
                    'image_count' => 0,
                    'video_count' => 0,
                ];
            }
            $groups[$day]['items'][] = $item;
            if (($item['kind'] ?? '') === 'video') {
                $groups[$day]['video_count']++;
            } else {
                $groups[$day]['image_count']++;
            }
        }

        return array_values($groups);
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function groupByAnniversary(array $items, TemplatePackInstallation $install): array
    {
        $opts = collect($this->anniversaryOptions($install))->keyBy('id');
        $groups = [];
        $unbound = [
            'key' => 'unbound',
            'title' => '未绑定纪念日',
            'subtitle' => '可在上传时选择纪念日',
            'bind_type' => 'anniversary',
            'items' => [],
            'image_count' => 0,
            'video_count' => 0,
        ];

        foreach ($items as $item) {
            $meta = $item['meta'] ?? [];
            $aid = $meta['anniversary_id'] ?? null;
            if (! $aid || ($meta['bind_type'] ?? '') !== 'anniversary') {
                $unbound['items'][] = $item;
                ($item['kind'] ?? '') === 'video' ? $unbound['video_count']++ : $unbound['image_count']++;

                continue;
            }
            if (! isset($groups[$aid])) {
                $opt = $opts->get($aid);
                $groups[$aid] = [
                    'key' => $aid,
                    'title' => $opt['title'] ?? ($meta['title'] ?? '纪念日'),
                    'subtitle' => $opt['date'] ?? null,
                    'bind_type' => 'anniversary',
                    'anniversary_id' => $aid,
                    'items' => [],
                    'image_count' => 0,
                    'video_count' => 0,
                ];
            }
            $groups[$aid]['items'][] = $item;
            ($item['kind'] ?? '') === 'video' ? $groups[$aid]['video_count']++ : $groups[$aid]['image_count']++;
        }

        $result = array_values($groups);
        if (count($unbound['items'])) {
            $result[] = $unbound;
        }

        return $result;
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function groupByEvent(array $items): array
    {
        $groups = [];
        $unbound = [
            'key' => 'unbound',
            'title' => '未绑定事件',
            'subtitle' => '可关联约会/礼物等记录',
            'bind_type' => 'event',
            'items' => [],
            'image_count' => 0,
            'video_count' => 0,
        ];

        foreach ($items as $item) {
            $meta = $item['meta'] ?? [];
            $rid = $item['attachable_id'] ?? ($meta['record_id'] ?? null);
            if (! $rid || ($meta['bind_type'] ?? '') !== 'event') {
                $unbound['items'][] = $item;
                ($item['kind'] ?? '') === 'video' ? $unbound['video_count']++ : $unbound['image_count']++;

                continue;
            }
            if (! isset($groups[$rid])) {
                $groups[$rid] = [
                    'key' => $rid,
                    'title' => $meta['title'] ?? '事件相册',
                    'subtitle' => $item['captured_on'] ?? null,
                    'bind_type' => 'event',
                    'record_id' => $rid,
                    'items' => [],
                    'image_count' => 0,
                    'video_count' => 0,
                ];
            }
            $groups[$rid]['items'][] = $item;
            ($item['kind'] ?? '') === 'video' ? $groups[$rid]['video_count']++ : $groups[$rid]['image_count']++;
        }

        $result = array_values($groups);
        if (count($unbound['items'])) {
            $result[] = $unbound;
        }

        return $result;
    }

    protected function recordTitle(Record $r): string
    {
        $p = $r->payload ?? [];
        $typeTitle = $r->recordType?->title ?: $r->type;
        $detail = $p['title'] ?? $p['place'] ?? $p['activity'] ?? $p['item'] ?? $p['body'] ?? null;
        if ($detail) {
            return $typeTitle.' · '.Str::limit((string) $detail, 24, '…');
        }

        return $typeTitle;
    }

    protected function loveDayLabel(array $item): ?string
    {
        $day = $item['meta']['love_day'] ?? null;

        return $day ? ('恋爱第 '.$day.' 天') : null;
    }
}
