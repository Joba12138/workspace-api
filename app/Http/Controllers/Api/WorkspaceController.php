<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Workspace;
use App\Services\WorkspaceBootstrap;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function __construct(protected WorkspaceBootstrap $bootstrap) {}

    public function index(Request $request)
    {
        $items = Membership::with('workspace')
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->workspace->id,
                'name' => $m->workspace->name,
                'role' => $m->role,
                'created_at' => ShanghaiTime::format($m->workspace->created_at),
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $workspace = DB::transaction(function () use ($data, $request) {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'owner_id' => $request->user()->id,
            ]);

            Membership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'role' => 'owner',
                'focus_stage_kind' => 'daily',
            ]);

            $this->bootstrap->provision($request->user(), $workspace, 'daily');
            $this->bootstrap->installDefaultPacks($workspace, $request->user());

            return $workspace;
        });

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => 'owner',
            ],
        ], 201);
    }
}
