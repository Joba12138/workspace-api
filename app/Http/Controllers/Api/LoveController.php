<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Member;
use App\Models\Record;
use App\Services\LoveAlbumService;
use App\Services\LoveAnniversaryService;
use App\Services\LovePackService;
use App\Services\WorkspaceBootstrap;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class LoveController extends Controller
{
    public function __construct(
        protected LovePackService $love,
        protected LoveAnniversaryService $anniversaries,
        protected LoveAlbumService $loveAlbum,
        protected WorkspaceBootstrap $bootstrap,
    ) {}

    public function profile(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        return response()->json(['data' => $this->love->profile($install)]);
    }

    /** 绑定伴侣：新建或选择成员 + 配偶亲属边 + 恋爱起始日 */
    public function bindPartner(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);
        $self = $this->bootstrap->ensureSelfMember($request->user(), $workspaceId);

        $data = $request->validate([
            'partner_member_id' => ['nullable', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'partner_name' => ['nullable', 'string', 'max:50'],
            'partner_gender' => ['nullable', 'string', 'max:20'],
            'partner_birthday' => ['nullable', 'date'],
            'dating_at' => ['nullable', 'date'],
        ]);

        if (empty($data['partner_member_id']) && empty($data['partner_name'])) {
            return response()->json(['message' => '请选择已有成员或填写伴侣姓名'], 422);
        }

        try {
            $install = $this->anniversaries->bindPartner($install, $self, $data, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->love->profile($install)]);
    }

    public function unbindPartner(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);
        $install = $this->anniversaries->unbindPartner($install);

        return response()->json(['data' => $this->love->profile($install)]);
    }

    /** 设置恋爱/订婚/结婚起始日 */
    public function updateDates(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        $data = $request->validate([
            'dating_at' => ['sometimes', 'nullable', 'date'],
            'engaged_at' => ['sometimes', 'nullable', 'date'],
            'married_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $install = $this->anniversaries->updateDates($install, $data);

        return response()->json(['data' => $this->love->profile($install)]);
    }

    public function addAnniversary(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:40'],
            'date' => ['required', 'date'],
            'yearly' => ['boolean'],
            'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        $install = $this->anniversaries->addCustomAnniversary($install, $data);

        return response()->json([
            'data' => [
                'profile' => $this->love->profile($install),
                'upcoming' => $this->anniversaries->upcoming($install),
            ],
        ], 201);
    }

    public function removeAnniversary(Request $request, string $id)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);
        $install = $this->anniversaries->removeCustomAnniversary($install, $id);

        return response()->json([
            'data' => [
                'profile' => $this->love->profile($install),
                'upcoming' => $this->anniversaries->upcoming($install),
            ],
        ]);
    }

    /** 恋爱 → 备婚 / 备婚 → 婚姻 / 恋爱 → 婚姻 */
    public function upgrade(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        $data = $request->validate([
            'to_phase' => ['nullable', Rule::in([LovePackService::PHASE_ENGAGED, LovePackService::PHASE_MARRIED])],
            'changed_at' => ['nullable', 'date'],
            'married_at' => ['nullable', 'date'],
            'theme_key' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            'color_soft' => ['nullable', 'string', 'max:20'],
            'display_title' => ['nullable', 'string', 'max:40'],
            'partner_member_id' => ['nullable', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'switch_life_stage' => ['boolean'],
        ]);

        $toPhase = $data['to_phase'] ?? LovePackService::PHASE_MARRIED;

        $self = ($data['switch_life_stage'] ?? true)
            ? $this->bootstrap->ensureSelfMember($request->user(), $workspaceId)
            : null;

        try {
            $install = $this->love->transitionPhase($install, $toPhase, $data, $request->user()->id, $self);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($data['switch_life_stage'] ?? true) {
            $membership = $request->attributes->get('membership');
            if ($membership) {
                $membership->focus_stage_kind = $toPhase === LovePackService::PHASE_ENGAGED
                    ? 'engaged'
                    : 'married';
                $membership->save();
            }
        }

        return response()->json(['data' => $this->love->profile($install)]);
    }

    public function updateTheme(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        $data = $request->validate([
            'theme_key' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            'color_soft' => ['nullable', 'string', 'max:20'],
            'display_title' => ['nullable', 'string', 'max:40'],
            'partner_member_id' => ['nullable', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
        ]);

        $install = $this->love->updateTheme($install, $data);

        return response()->json(['data' => $this->love->profile($install)]);
    }

    /** 恋爱/备婚/婚姻栏目首页数据 */
    public function home(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);
        $profile = $this->love->profile($install);

        $recent = Record::with(['recordType', 'member'])
            ->where('workspace_id', $workspaceId)
            ->whereIn('type', [
                'date_night', 'anniversary', 'love_note', 'gift',
                'wedding_prep', 'wedding', 'daily_together', 'wishlist',
            ])
            ->orderByDesc('happened_at')
            ->limit(20)
            ->get()
            ->map(fn (Record $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_title' => $r->recordType?->title,
                'member_name' => $r->member?->name,
                'happened_at' => ShanghaiTime::format($r->happened_at),
                'payload' => $r->payload,
                'note' => $r->note,
            ]);

        $candidates = Member::where('workspace_id', $workspaceId)
            ->where('type', '!=', 'self')
            ->orderBy('created_at')
            ->get(['id', 'name', 'type', 'gender', 'birthday'])
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'type' => $m->type,
                'gender' => $m->gender,
                'birthday' => optional($m->birthday)?->toDateString(),
            ]);

        return response()->json([
            'data' => [
                'profile' => $profile,
                'recent' => $recent,
                'quick_types' => $this->quickTypes($profile['phase']),
                'upcoming' => $this->anniversaries->upcoming($install),
                'member_candidates' => $candidates,
            ],
        ]);
    }

    /** 恋爱相册：按日期 / 纪念日 / 事件分组 */
    public function album(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);
        $groupBy = $request->string('group_by', 'date')->toString();
        if (! in_array($groupBy, ['date', 'anniversary', 'event'], true)) {
            $groupBy = 'date';
        }

        return response()->json(['data' => $this->loveAlbum->album($install, $groupBy)]);
    }

    /** 上传后绑定日期 / 纪念日 / 事件 */
    public function bindPhoto(Request $request, string $id)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $install = $this->love->ensureInstallation($workspaceId, $request->user()->id);

        $attachment = Attachment::where('workspace_id', $workspaceId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'bind_type' => ['required', Rule::in(['date', 'anniversary', 'event', 'milestone'])],
            'captured_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:80'],
            'record_id' => ['nullable', 'uuid', Rule::exists('records', 'id')->where('workspace_id', $workspaceId)],
            'anniversary_id' => ['nullable', 'string', 'max:80'],
            'milestone_key' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $attachment = $this->loveAlbum->bind($attachment, $install, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => app(\App\Services\AttachmentService::class)->toArray($attachment)]);
    }

    private function quickTypes(?string $phase): array
    {
        $base = [
            ['key' => 'date_night', 'title' => $phase === 'married' ? '约会/出行' : '约会'],
            ['key' => 'anniversary', 'title' => '纪念日'],
            ['key' => 'love_note', 'title' => '甜蜜瞬间'],
            ['key' => 'gift', 'title' => '礼物'],
            ['key' => 'wishlist', 'title' => '心愿清单'],
        ];

        if ($phase === 'engaged') {
            array_unshift($base, ['key' => 'wedding_prep', 'title' => '备婚事项']);
            $base[] = ['key' => 'wedding', 'title' => '婚礼/仪式'];
        }

        if ($phase === 'married') {
            array_unshift($base, ['key' => 'daily_together', 'title' => '一起的日常']);
            $base[] = ['key' => 'wedding', 'title' => '婚礼/仪式'];
        }

        return $base;
    }
}
