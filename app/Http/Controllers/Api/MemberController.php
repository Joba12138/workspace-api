<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Stage;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $members = Member::with(['stages' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Member $m) => $this->payload($m));

        return response()->json(['data' => $members]);
    }

    public function store(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::in(['self', 'partner', 'child', 'fetus', 'elder', 'pet', 'other'])],
            'gender' => ['nullable', 'string', 'max:20'],
            'birthday' => ['nullable', 'date'],
            'stage_kind' => ['nullable', 'string', 'max:40'],
            'stage_meta' => ['nullable', 'array'],
        ]);

        $member = DB::transaction(function () use ($data, $workspaceId) {
            $member = Member::create([
                'workspace_id' => $workspaceId,
                'name' => $data['name'],
                'type' => $data['type'],
                'gender' => $data['gender'] ?? null,
                'birthday' => $data['birthday'] ?? null,
            ]);

            if (! empty($data['stage_kind'])) {
                Stage::create([
                    'workspace_id' => $workspaceId,
                    'member_id' => $member->id,
                    'kind' => $data['stage_kind'],
                    'title' => $data['stage_kind'],
                    'started_at' => now(),
                    'meta' => $data['stage_meta'] ?? null,
                ]);
            }

            return $member->load(['stages' => fn ($q) => $q->whereNull('ended_at')]);
        });

        return response()->json(['data' => $this->payload($member)], 201);
    }

    public function show(Request $request, string $id)
    {
        $member = $this->findInWorkspace($request, $id);
        $member->load('stages');

        return response()->json(['data' => $this->payload($member, true)]);
    }

    public function update(Request $request, string $id)
    {
        $member = $this->findInWorkspace($request, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'type' => ['sometimes', Rule::in(['self', 'partner', 'child', 'fetus', 'elder', 'pet', 'other'])],
            'gender' => ['nullable', 'string', 'max:20'],
            'birthday' => ['nullable', 'date'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $member->fill($data)->save();

        return response()->json(['data' => $this->payload($member->fresh()->load('stages'))]);
    }

    public function destroy(Request $request, string $id)
    {
        $member = $this->findInWorkspace($request, $id);
        $member->delete();

        return response()->json(['message' => 'ok']);
    }

    /** 胎儿出生：同一 Member 切换 type + Stage */
    public function birth(Request $request, string $id)
    {
        $member = $this->findInWorkspace($request, $id);

        $data = $request->validate([
            'born_at' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:50'],
        ]);

        if ($member->type !== 'fetus') {
            return response()->json(['message' => '仅胎儿档案可执行出生切换'], 422);
        }

        $member = DB::transaction(function () use ($member, $data, $request) {
            Stage::where('member_id', $member->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => ShanghaiTime::parse($data['born_at'])]);

            $member->update([
                'type' => 'child',
                'born_at' => ShanghaiTime::parse($data['born_at']),
                'birthday' => ShanghaiTime::parse($data['born_at'])->toDateString(),
                'name' => $data['name'] ?? $member->name,
            ]);

            Stage::create([
                'workspace_id' => $request->attributes->get('workspace_id'),
                'member_id' => $member->id,
                'kind' => 'newborn',
                'title' => '新生儿',
                'started_at' => ShanghaiTime::parse($data['born_at']),
            ]);

            app(\App\Services\VaccineScheduleService::class)
                ->ensureForMember($request->attributes->get('workspace_id'), $member->fresh());

            return $member->fresh()->load(['stages' => fn ($q) => $q->orderByDesc('started_at')]);
        });

        return response()->json(['data' => $this->payload($member, true)]);
    }

    public function storeStage(Request $request, string $id)
    {
        $member = $this->findInWorkspace($request, $id);
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'kind' => ['required', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:50'],
            'started_at' => ['required', 'date'],
            'meta' => ['nullable', 'array'],
            'end_previous' => ['boolean'],
        ]);

        $stage = DB::transaction(function () use ($member, $workspaceId, $data) {
            if ($data['end_previous'] ?? true) {
                Stage::where('member_id', $member->id)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => ShanghaiTime::parse($data['started_at'])]);
            }

            return Stage::create([
                'workspace_id' => $workspaceId,
                'member_id' => $member->id,
                'kind' => $data['kind'],
                'title' => $data['title'] ?? $data['kind'],
                'started_at' => ShanghaiTime::parse($data['started_at']),
                'meta' => $data['meta'] ?? null,
            ]);
        });

        return response()->json([
            'data' => [
                'id' => $stage->id,
                'kind' => $stage->kind,
                'title' => $stage->title,
                'started_at' => ShanghaiTime::format($stage->started_at),
                'ended_at' => ShanghaiTime::format($stage->ended_at),
                'meta' => $stage->meta,
            ],
        ], 201);
    }

    private function findInWorkspace(Request $request, string $id): Member
    {
        return Member::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
    }

    private function payload(Member $m, bool $allStages = false): array
    {
        $stages = $m->relationLoaded('stages') ? $m->stages : collect();

        return [
            'id' => $m->id,
            'name' => $m->name,
            'type' => $m->type,
            'gender' => $m->gender,
            'birthday' => optional($m->birthday)?->toDateString(),
            'born_at' => ShanghaiTime::format($m->born_at),
            'avatar_url' => $m->avatar_url,
            'meta' => $m->meta,
            'current_stage' => $stages->firstWhere('ended_at', null)
                ? [
                    'id' => $stages->firstWhere('ended_at', null)->id,
                    'kind' => $stages->firstWhere('ended_at', null)->kind,
                    'title' => $stages->firstWhere('ended_at', null)->title,
                    'started_at' => ShanghaiTime::format($stages->firstWhere('ended_at', null)->started_at),
                    'meta' => $stages->firstWhere('ended_at', null)->meta,
                ]
                : null,
            'stages' => $allStages
                ? $stages->map(fn (Stage $s) => [
                    'id' => $s->id,
                    'kind' => $s->kind,
                    'title' => $s->title,
                    'started_at' => ShanghaiTime::format($s->started_at),
                    'ended_at' => ShanghaiTime::format($s->ended_at),
                    'meta' => $s->meta,
                ])->values()
                : null,
        ];
    }
}
