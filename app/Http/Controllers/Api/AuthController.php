<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceBootstrap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected WorkspaceBootstrap $bootstrap) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:50'],
            'focus_stage' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', 'string', 'max:20'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $workspace = Workspace::create([
                'name' => $data['workspace_name'] ?? ($data['name'].'的人生'),
                'owner_id' => $user->id,
            ]);

            Membership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'focus_stage_kind' => $data['focus_stage'] ?? 'daily',
            ]);

            $self = $this->bootstrap->provision(
                $user,
                $workspace,
                $data['focus_stage'] ?? 'daily'
            );

            if (! empty($data['gender'])) {
                $self->update(['gender' => $data['gender']]);
            }

            $this->bootstrap->installDefaultPacks($workspace, $user);

            return $user;
        });

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['邮箱或密码不正确'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ok']);
    }

    private function userPayload(User $user): array
    {
        $memberships = Membership::with('workspace')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'focus_stage_kind' => $m->focus_stage_kind,
                'workspace' => [
                    'id' => $m->workspace->id,
                    'name' => $m->workspace->name,
                ],
            ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'memberships' => $memberships,
        ];
    }
}
