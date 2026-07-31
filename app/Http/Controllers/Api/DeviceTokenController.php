<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'push_client_id' => ['required', 'string', 'max:128'],
            'platform' => ['nullable', 'string', Rule::in(['ios', 'android', 'harmony', 'other'])],
            'device_model' => ['nullable', 'string', 'max:80'],
        ]);

        $token = DeviceToken::updateOrCreate(
            ['push_client_id' => $data['push_client_id']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'device_model' => $data['device_model'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $token->id,
                'push_client_id' => $token->push_client_id,
                'platform' => $token->platform,
            ],
        ]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'push_client_id' => ['required', 'string', 'max:128'],
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('push_client_id', $data['push_client_id'])
            ->delete();

        return response()->json(['message' => 'ok']);
    }
}
