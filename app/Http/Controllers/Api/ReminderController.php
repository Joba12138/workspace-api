<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReminderController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $query = Reminder::where('workspace_id', $workspaceId)->orderBy('due_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->input('due') === 'today') {
            $start = now(ShanghaiTime::TZ)->startOfDay();
            $end = now(ShanghaiTime::TZ)->endOfDay();
            $query->whereBetween('due_at', [$start, $end]);
        }

        $items = $query->limit(100)->get()->map(fn (Reminder $r) => $this->payload($r));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'due_at' => ['required', 'date'],
            'member_id' => ['nullable', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'recurrence' => ['nullable', 'array'],
            'related_type' => ['nullable', 'string', 'max:40'],
            'related_key' => ['nullable', 'string', 'max:60'],
        ]);

        $reminder = Reminder::create([
            'workspace_id' => $workspaceId,
            'member_id' => $data['member_id'] ?? null,
            'title' => $data['title'],
            'due_at' => ShanghaiTime::parse($data['due_at']),
            'recurrence' => $data['recurrence'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_key' => $data['related_key'] ?? null,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($reminder)], 201);
    }

    public function update(Request $request, string $id)
    {
        $reminder = Reminder::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:100'],
            'due_at' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['pending', 'done', 'dismissed'])],
            'recurrence' => ['nullable', 'array'],
        ]);

        if (isset($data['due_at'])) {
            $data['due_at'] = ShanghaiTime::parse($data['due_at']);
        }

        $reminder->fill($data)->save();

        return response()->json(['data' => $this->payload($reminder)]);
    }

    public function destroy(Request $request, string $id)
    {
        $reminder = Reminder::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
        $reminder->delete();

        return response()->json(['message' => 'ok']);
    }

    public function restore(Request $request, string $id)
    {
        $reminder = Reminder::onlyTrashed()
            ->where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
        $reminder->restore();

        return response()->json(['data' => $this->payload($reminder)]);
    }

    private function payload(Reminder $r): array
    {
        return [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'title' => $r->title,
            'due_at' => ShanghaiTime::format($r->due_at),
            'recurrence' => $r->recurrence,
            'related_type' => $r->related_type,
            'related_key' => $r->related_key,
            'status' => $r->status,
        ];
    }
}
