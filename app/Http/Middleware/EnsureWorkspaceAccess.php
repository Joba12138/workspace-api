<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $workspaceId = $request->header('X-Workspace-Id') ?: $request->input('workspace_id');

        if (! $workspaceId) {
            return response()->json(['message' => '缺少工作空间 X-Workspace-Id'], 422);
        }

        $membership = Membership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => '无权访问该工作空间'], 403);
        }

        if ($ability === 'write' && ! $membership->canWrite()) {
            return response()->json(['message' => '当前角色为只读，无法写入'], 403);
        }

        $request->attributes->set('workspace_id', $workspaceId);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
