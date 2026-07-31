<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppleTokenVerifier;
use App\Services\WorkspaceBootstrap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected WorkspaceBootstrap $bootstrap,
        protected AppleTokenVerifier $appleTokens,
    ) {}

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

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
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

    /**
     * Apple 一键登录 / 注册
     * 前端：uni.login({ provider: 'apple' }) → authResult.access_token 即 identityToken
     */
    public function apple(Request $request)
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string'],
            'authorization_code' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:120'],
            'full_name' => ['nullable'],
            'given_name' => ['nullable', 'string', 'max:50'],
            'family_name' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:50'],
            'workspace_name' => ['nullable', 'string', 'max:50'],
            'focus_stage' => ['nullable', 'string', 'max:40'],
        ]);

        $claims = $this->appleTokens->verify($data['identity_token']);
        $appleId = (string) $claims['sub'];
        $emailFromToken = is_string($claims['email'] ?? null) ? $claims['email'] : null;
        $email = $data['email'] ?: $emailFromToken;

        $displayName = $this->resolveAppleDisplayName($data, $email);

        $user = User::where('apple_id', $appleId)->first();
        $created = false;

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user && ! $user->apple_id) {
                $user->apple_id = $appleId;
                $user->save();
            }
        }

        if (! $user) {
            $created = true;
            $user = DB::transaction(function () use ($appleId, $email, $displayName, $data) {
                $user = User::create([
                    'apple_id' => $appleId,
                    'name' => $displayName,
                    'email' => $email,
                    'password' => null,
                ]);
                if ($email) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $workspace = Workspace::create([
                    'name' => $data['workspace_name'] ?? ($displayName.'的人生'),
                    'owner_id' => $user->id,
                ]);

                Membership::create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'focus_stage_kind' => $data['focus_stage'] ?? 'daily',
                ]);

                $this->bootstrap->provision(
                    $user,
                    $workspace,
                    $data['focus_stage'] ?? 'daily'
                );
                $this->bootstrap->installDefaultPacks($workspace, $user);

                return $user;
            });
        } elseif ($displayName && ($user->name === 'Apple 用户' || str_starts_with((string) $user->name, 'Apple'))) {
            // 首次授权后补全姓名
            if (! empty($data['name']) || ! empty($data['given_name']) || ! empty($data['full_name'])) {
                $user->name = $displayName;
                $user->save();
            }
        }

        if ($email && ! $user->email) {
            $user->email = $email;
            $user->email_verified_at = $user->email_verified_at ?: now();
            $user->save();
        }

        $token = $user->createToken('apple')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
                'is_new' => $created,
            ],
        ], $created ? 201 : 200);
    }

    private function resolveAppleDisplayName(array $data, ?string $email): string
    {
        if (! empty($data['name'])) {
            return trim((string) $data['name']);
        }

        $full = $data['full_name'] ?? null;
        if (is_string($full) && $full !== '') {
            $decoded = json_decode($full, true);
            $full = is_array($decoded) ? $decoded : ['givenName' => $full];
        }
        if (is_array($full)) {
            $given = trim((string) ($full['givenName'] ?? $full['given_name'] ?? $data['given_name'] ?? ''));
            $family = trim((string) ($full['familyName'] ?? $full['family_name'] ?? $data['family_name'] ?? ''));
            $joined = trim($family.$given) ?: trim($given.' '.$family);
            if ($joined !== '') {
                return $joined;
            }
        }

        $given = trim((string) ($data['given_name'] ?? ''));
        $family = trim((string) ($data['family_name'] ?? ''));
        $joined = trim($family.$given) ?: trim($given.' '.$family);
        if ($joined !== '') {
            return $joined;
        }

        if ($email) {
            return Str::before($email, '@') ?: 'Apple 用户';
        }

        return 'Apple 用户';
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
            'apple_bound' => (bool) $user->apple_id,
            'memberships' => $memberships,
        ];
    }
}
