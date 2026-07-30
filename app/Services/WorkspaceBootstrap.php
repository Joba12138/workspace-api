<?php

namespace App\Services;

use App\Models\LifeStageDef;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Stage;
use App\Models\TemplatePack;
use App\Models\TemplatePackInstallation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceBootstrap
{
    public function provision(User $user, Workspace $workspace, ?string $focusStage = 'daily'): Member
    {
        $self = Member::create([
            'workspace_id' => $workspace->id,
            'linked_user_id' => $user->id,
            'name' => $user->name,
            'type' => 'self',
        ]);

        $stageKey = $focusStage ?: 'daily';
        if (LifeStageDef::where('key', $stageKey)->exists()) {
            Stage::create([
                'workspace_id' => $workspace->id,
                'member_id' => $self->id,
                'kind' => $stageKey,
                'title' => LifeStageDef::find($stageKey)?->title ?? $stageKey,
                'started_at' => now(),
            ]);
        }

        Membership::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->update(['focus_stage_kind' => $stageKey]);

        return $self;
    }

    public function installDefaultPacks(Workspace $workspace, User $user): void
    {
        foreach (['pregnancy', 'newborn', 'journal', 'love'] as $key) {
            if (! TemplatePack::where('key', $key)->exists()) {
                continue;
            }
            TemplatePackInstallation::firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'pack_key' => $key,
                ],
                [
                    'phase' => $key === 'love' ? 'dating' : null,
                    'installed_at' => now(),
                    'installed_by' => $user->id,
                ]
            );
        }
    }

    public function ensureSelfMember(User $user, string $workspaceId): Member
    {
        $existing = Member::where('workspace_id', $workspaceId)
            ->where('linked_user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);

            return $this->provision($user, $workspace, Membership::where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->value('focus_stage_kind') ?: 'daily');
        });
    }
}
