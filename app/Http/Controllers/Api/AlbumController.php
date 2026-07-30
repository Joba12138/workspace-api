<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Member;
use App\Services\AttachmentService;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;

class AlbumController extends Controller
{
    public function __construct(protected AttachmentService $attachments) {}

    /**
     * 云相册：按宝宝日龄阶段分组（对齐亲宝宝「按年龄整理」）
     */
    public function index(Request $request, string $memberId)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $member = Member::where('workspace_id', $workspaceId)->where('id', $memberId)->firstOrFail();

        $query = Attachment::where('workspace_id', $workspaceId)
            ->where('member_id', $memberId)
            ->where('module', 'album')
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at');

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind'));
        }

        $items = $query->get();
        $mapped = $items->map(fn (Attachment $a) => $this->attachments->toArray($a));

        $groups = $this->groupByAge($mapped->all(), $member);

        return response()->json([
            'data' => [
                'member_id' => $member->id,
                'member_name' => $member->name,
                'total' => $items->count(),
                'image_count' => $items->where('kind', 'image')->count(),
                'video_count' => $items->where('kind', 'video')->count(),
                'groups' => $groups,
                'recent' => $mapped->take(30)->values(),
            ],
        ]);
    }

    public function updateMeta(Request $request, string $id)
    {
        $attachment = Attachment::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->where('module', 'album')
            ->firstOrFail();

        $data = $request->validate([
            'captured_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        if (array_key_exists('captured_at', $data) && $data['captured_at']) {
            $captured = ShanghaiTime::parse($data['captured_at']);
            $attachment->captured_at = $captured;
            if ($attachment->member_id) {
                $attachment->day_age = $this->attachments->calcDayAge($attachment->member_id, $captured);
            }
        }
        if (array_key_exists('note', $data)) {
            $attachment->note = $data['note'];
        }
        $attachment->save();

        return response()->json(['data' => $this->attachments->toArray($attachment)]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function groupByAge(array $items, Member $member): array
    {
        $buckets = [
            ['key' => 'y1', 'title' => '1周岁', 'min' => 366, 'max' => 99999],
            ['key' => 'm6', 'title' => '半岁啦', 'min' => 180, 'max' => 365],
            ['key' => 'm1', 'title' => '满月了', 'min' => 30, 'max' => 179],
            ['key' => 'born', 'title' => '刚出生', 'min' => 1, 'max' => 29],
            ['key' => 'unknown', 'title' => '未标注日期', 'min' => null, 'max' => null],
        ];

        $grouped = [];
        foreach ($buckets as $b) {
            $grouped[$b['key']] = [
                'key' => $b['key'],
                'title' => $b['title'],
                'items' => [],
                'image_count' => 0,
                'video_count' => 0,
            ];
        }

        foreach ($items as $item) {
            $day = $item['day_age'] ?? null;
            $bucket = 'unknown';
            if ($day !== null) {
                foreach ($buckets as $b) {
                    if ($b['min'] === null) {
                        continue;
                    }
                    if ($day >= $b['min'] && $day <= $b['max']) {
                        $bucket = $b['key'];
                        break;
                    }
                }
            }
            $grouped[$bucket]['items'][] = $item;
            if (($item['kind'] ?? '') === 'video') {
                $grouped[$bucket]['video_count']++;
            } else {
                $grouped[$bucket]['image_count']++;
            }
        }

        return array_values(array_filter($grouped, fn ($g) => count($g['items']) > 0));
    }
}
