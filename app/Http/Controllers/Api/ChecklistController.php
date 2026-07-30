<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Member;
use App\Models\Record;
use App\Services\VaccineScheduleService;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChecklistController extends Controller
{
    public function __construct(protected VaccineScheduleService $vaccines) {}

    /** 宝宝接种表：按成员自动生成/返回 */
    public function vaccineSchedule(Request $request, string $memberId)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $member = Member::where('workspace_id', $workspaceId)->where('id', $memberId)->firstOrFail();

        $checklist = $this->vaccines->ensureForMember($workspaceId, $member);

        $groups = [];
        foreach ($checklist->items as $item) {
            $month = $item->age_months ?? 0;
            $key = (string) $month;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'age_months' => $month,
                    'label' => $month.'月龄',
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $this->itemPayload($item);
        }

        return response()->json([
            'data' => [
                'checklist_id' => $checklist->id,
                'member_id' => $member->id,
                'title' => $checklist->title,
                'groups' => array_values($groups),
            ],
        ]);
    }

    public function completeItem(Request $request, string $itemId)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $item = ChecklistItem::where('workspace_id', $workspaceId)
            ->where('id', $itemId)
            ->firstOrFail();

        $data = $request->validate([
            'done_at' => ['nullable', 'date'],
            'create_record' => ['boolean'],
            'member_id' => ['nullable', 'uuid'],
        ]);

        $doneAt = ShanghaiTime::parse($data['done_at'] ?? now('Asia/Shanghai')->toIso8601String());

        $record = null;
        DB::transaction(function () use ($item, $doneAt, $data, $request, $workspaceId, &$record) {
            $item->status = 'done';
            $item->done_at = $doneAt;

            if ($data['create_record'] ?? true) {
                $memberId = $data['member_id']
                    ?? Checklist::where('id', $item->checklist_id)->value('member_id');

                if ($memberId) {
                    $record = Record::create([
                        'workspace_id' => $workspaceId,
                        'member_id' => $memberId,
                        'type' => 'vaccine',
                        'happened_at' => $doneAt,
                        'payload' => [
                            'name' => $item->title,
                            'dose_no' => $item->dose_no,
                            'dose_total' => $item->dose_total,
                            'checklist_item_id' => $item->id,
                        ],
                        'note' => null,
                        'created_by' => $request->user()->id,
                    ]);
                    $item->source_record_id = $record->id;
                }
            }

            $item->save();
        });

        return response()->json([
            'data' => [
                'item' => $this->itemPayload($item->fresh()),
                'record_id' => $record?->id,
            ],
        ]);
    }

    public function resetItem(Request $request, string $itemId)
    {
        $item = ChecklistItem::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $itemId)
            ->firstOrFail();

        $item->update([
            'status' => 'pending',
            'done_at' => null,
            'source_record_id' => null,
        ]);

        return response()->json(['data' => $this->itemPayload($item)]);
    }

    public function storeCustomItem(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'member_id' => ['required', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'title' => ['required', 'string', 'max:80'],
            'age_months' => ['nullable', 'integer', 'min:0', 'max:216'],
            'recommended_on' => ['nullable', 'date'],
            'dose_no' => ['nullable', 'integer', 'min:1'],
            'dose_total' => ['nullable', 'integer', 'min:1'],
            'is_free' => ['boolean'],
        ]);

        $member = Member::where('workspace_id', $workspaceId)->where('id', $data['member_id'])->firstOrFail();
        $checklist = $this->vaccines->ensureForMember($workspaceId, $member);

        $item = ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'workspace_id' => $workspaceId,
            'title' => $data['title'],
            'dose_no' => $data['dose_no'] ?? null,
            'dose_total' => $data['dose_total'] ?? null,
            'is_free' => $data['is_free'] ?? false,
            'age_months' => $data['age_months'] ?? null,
            'recommended_on' => $data['recommended_on'] ?? null,
            'status' => 'pending',
            'sort' => 9000 + ($data['age_months'] ?? 0),
        ]);

        return response()->json(['data' => $this->itemPayload($item)], 201);
    }

    private function itemPayload(ChecklistItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'dose_no' => $item->dose_no,
            'dose_total' => $item->dose_total,
            'is_free' => $item->is_free,
            'age_months' => $item->age_months,
            'recommended_on' => optional($item->recommended_on)?->format('Y-m-d'),
            'recommended_on_text' => $item->recommended_on
                ? $item->recommended_on->format('Y年n月j日')
                : null,
            'status' => $item->status,
            'done_at' => ShanghaiTime::format($item->done_at),
            'source_record_id' => $item->source_record_id,
        ];
    }
}
