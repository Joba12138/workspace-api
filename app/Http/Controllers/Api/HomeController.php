<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LifeStageDef;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\Stage;
use App\Models\TemplatePackInstallation;
use App\Services\WorkspaceBootstrap;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(protected WorkspaceBootstrap $bootstrap) {}

    /** 我为中心的工作台首页汇总 */
    public function show(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $user = $request->user();
        $membership = $request->attributes->get('membership');

        $self = $this->bootstrap->ensureSelfMember($user, $workspaceId);

        $focusKind = $membership->focus_stage_kind ?: 'daily';
        $stageDef = LifeStageDef::find($focusKind);

        $installed = TemplatePackInstallation::with('pack')
            ->where('workspace_id', $workspaceId)
            ->get()
            ->map(function ($i) {
                $pres = $i->resolvePresentation($i->pack);

                return [
                    'key' => $i->pack_key,
                    'title' => $pres['title'] ?? $i->pack?->title,
                    'subtitle' => $pres['subtitle'] ?? $i->pack?->subtitle,
                    'color' => $pres['color'] ?? $i->pack?->color,
                    'color_soft' => $pres['color_soft'] ?? $i->pack?->color_soft,
                    'icon' => $i->pack?->icon,
                    'phase' => $pres['phase'] ?? null,
                    'hub' => $i->pack_key === 'love' ? 'love' : ($i->pack?->config['hub'] ?? null),
                ];
            });

        $primaryPack = $stageDef?->primary_pack;
        $relatedPacks = $stageDef?->pack_keys ?? [];

        // 主推在前
        $packs = $installed->sortBy(function ($p) use ($primaryPack, $relatedPacks) {
            if ($p['key'] === $primaryPack) {
                return 0;
            }
            $idx = array_search($p['key'], $relatedPacks, true);

            return $idx === false ? 100 : (10 + $idx);
        })->values();

        $recent = Record::with(['recordType', 'member'])
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('happened_at')
            ->limit(8)
            ->get()
            ->map(fn (Record $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_title' => $r->recordType?->title,
                'type_color' => $r->recordType?->color,
                'pack_key' => $r->recordType?->pack_key,
                'member_name' => $r->member?->name,
                'happened_at' => ShanghaiTime::format($r->happened_at),
                'note' => $r->note,
            ]);

        $reminders = Reminder::where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->whereBetween('due_at', [
                now('Asia/Shanghai')->startOfDay(),
                now('Asia/Shanghai')->endOfDay(),
            ])
            ->orderBy('due_at')
            ->limit(10)
            ->get()
            ->map(fn (Reminder $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'due_at' => ShanghaiTime::format($r->due_at),
            ]);

        $babies = Member::where('workspace_id', $workspaceId)
            ->whereIn('type', ['fetus', 'child'])
            ->orderBy('created_at')
            ->get(['id', 'name', 'type', 'born_at', 'birthday', 'gender']);

        $lifeStages = LifeStageDef::where('is_core', true)->orderBy('sort')->get()->map(fn (LifeStageDef $s) => [
            'key' => $s->key,
            'title' => $s->title,
            'subtitle' => $s->subtitle,
            'primary_pack' => $s->primary_pack,
            'pack_keys' => $s->pack_keys,
            'active' => $s->key === $focusKind,
        ]);

        return response()->json([
            'data' => [
                'self' => [
                    'id' => $self->id,
                    'name' => $self->name,
                    'type' => $self->type,
                    'gender' => $self->gender,
                ],
                'focus_stage' => [
                    'kind' => $focusKind,
                    'title' => $stageDef?->title ?? $focusKind,
                    'subtitle' => $stageDef?->subtitle,
                    'primary_pack' => $primaryPack,
                ],
                'life_stages' => $lifeStages,
                'packs' => $packs,
                'babies' => $babies->map(fn (Member $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'type' => $b->type,
                    'born_at' => ShanghaiTime::format($b->born_at),
                    'birthday' => optional($b->birthday)?->toDateString(),
                    'day_age' => $this->dayAge($b),
                ]),
                'recent_records' => $recent,
                'today_reminders' => $reminders,
            ],
        ]);
    }

    public function setFocus(Request $request)
    {
        $data = $request->validate([
            'stage_kind' => ['required', 'string', 'max:40'],
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        /** @var Membership $membership */
        $membership = $request->attributes->get('membership');

        $def = LifeStageDef::find($data['stage_kind']);
        if (! $def) {
            return response()->json(['message' => '未知人生阶段'], 422);
        }

        $membership->focus_stage_kind = $def->key;
        $membership->save();

        $self = $this->bootstrap->ensureSelfMember($request->user(), $workspaceId);

        // 结束旧主轴阶段，开启新阶段（记在 self Member 上）
        Stage::where('member_id', $self->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        Stage::create([
            'workspace_id' => $workspaceId,
            'member_id' => $self->id,
            'kind' => $def->key,
            'title' => $def->title,
            'started_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'focus_stage' => [
                    'kind' => $def->key,
                    'title' => $def->title,
                    'subtitle' => $def->subtitle,
                    'primary_pack' => $def->primary_pack,
                ],
            ],
        ]);
    }

    private function dayAge(Member $b): ?int
    {
        $start = $b->born_at ?: $b->birthday;
        if (! $start) {
            return null;
        }

        return (int) now('Asia/Shanghai')->startOfDay()
            ->diffInDays(\Carbon\Carbon::parse($start)->timezone('Asia/Shanghai')->startOfDay()) + 1;
    }
}
