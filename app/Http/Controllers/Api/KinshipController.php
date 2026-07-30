<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KinshipEdge;
use App\Models\Member;
use App\Services\KinshipLabeler;
use App\Services\WorkspaceBootstrap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KinshipController extends Controller
{
    public function __construct(protected WorkspaceBootstrap $bootstrap) {}

    public function index(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $viewer = $this->bootstrap->ensureSelfMember($request->user(), $workspaceId);

        $focusId = $request->query('focus_member_id');
        $focus = $focusId
            ? Member::where('workspace_id', $workspaceId)->where('id', $focusId)->first()
            : null;

        $labeler = new KinshipLabeler($workspaceId);

        $members = Member::where('workspace_id', $workspaceId)
            ->where('id', '!=', $viewer->id)
            ->where('type', '!=', 'self')
            ->orderBy('created_at')
            ->get();

        $people = $members->map(function (Member $m) use ($labeler, $viewer, $focus) {
            $labeled = $labeler->label($viewer, $m, $focus);

            return [
                'id' => $m->id,
                'name' => $m->name,
                'type' => $m->type,
                'gender' => $m->gender,
                'label' => $labeled['label'],
                'relation_path' => $labeled['relation_path'],
                'via' => $labeled['via'],
                'perspective' => $focus ? 'focus:'.$focus->name : 'self',
            ];
        })->values();

        // 宝宝视角下：我是宝宝的谁（爸爸/妈妈…）
        $myLabelToFocus = null;
        if ($focus) {
            $myLabelToFocus = $labeler->label($focus, $viewer)['label'] ?? null;
        }

        return response()->json([
            'data' => [
                'viewer_id' => $viewer->id,
                'focus_member_id' => $focus?->id,
                'my_label_to_focus' => $myLabelToFocus,
                'people' => $people,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'from_member_id' => ['required', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'to_member_id' => ['required', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'relation' => ['required', Rule::in(['parent', 'spouse', 'sibling'])],
        ]);

        if ($data['from_member_id'] === $data['to_member_id']) {
            return response()->json(['message' => '不能与自己建立关系'], 422);
        }

        $edge = KinshipEdge::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('from_member_id', $data['from_member_id'])
            ->where('to_member_id', $data['to_member_id'])
            ->where('relation', $data['relation'])
            ->first();

        if ($edge) {
            if ($edge->trashed()) {
                $edge->restore();
            }
        } else {
            $edge = KinshipEdge::create([
                'workspace_id' => $workspaceId,
                'from_member_id' => $data['from_member_id'],
                'to_member_id' => $data['to_member_id'],
                'relation' => $data['relation'],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $edge->id,
                'from_member_id' => $edge->from_member_id,
                'to_member_id' => $edge->to_member_id,
                'relation' => $edge->relation,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $edge = KinshipEdge::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
        $edge->delete();

        return response()->json(['message' => 'ok']);
    }
}
