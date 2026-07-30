<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricDef;
use App\Models\MetricSample;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetricSampleController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $query = MetricSample::where('workspace_id', $workspaceId)
            ->orderByDesc('measured_at');

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->string('member_id'));
        }
        if ($request->filled('metric_key')) {
            $query->where('metric_key', $request->string('metric_key'));
        }

        $paginator = $query->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (MetricSample $s) => $this->payload($s))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function series(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'member_id' => ['required', 'uuid'],
            'metric_key' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = MetricSample::where('workspace_id', $workspaceId)
            ->where('member_id', $data['member_id'])
            ->where('metric_key', $data['metric_key'])
            ->orderBy('measured_at');

        if (! empty($data['from'])) {
            $query->where('measured_at', '>=', ShanghaiTime::parse($data['from']));
        }
        if (! empty($data['to'])) {
            $query->where('measured_at', '<=', ShanghaiTime::parse($data['to']));
        }

        $points = $query->get()->map(fn (MetricSample $s) => [
            'id' => $s->id,
            'value' => $s->value,
            'unit' => $s->unit,
            'measured_at' => ShanghaiTime::format($s->measured_at),
        ]);

        $def = MetricDef::find($data['metric_key']);

        return response()->json([
            'data' => [
                'metric_key' => $data['metric_key'],
                'title' => $def?->title,
                'unit' => $def?->unit,
                'color' => $def?->color,
                'points' => $points,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'member_id' => ['required', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'metric_key' => ['required', 'string', Rule::exists('metric_defs', 'key')],
            'value' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:20'],
            'measured_at' => ['required', 'date'],
            'source_record_id' => ['nullable', 'uuid'],
        ]);

        $def = MetricDef::findOrFail($data['metric_key']);

        $sample = MetricSample::create([
            'workspace_id' => $workspaceId,
            'member_id' => $data['member_id'],
            'metric_key' => $data['metric_key'],
            'value' => $data['value'],
            'unit' => $data['unit'] ?? $def->unit,
            'measured_at' => ShanghaiTime::parse($data['measured_at']),
            'source_record_id' => $data['source_record_id'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($sample)], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $sample = MetricSample::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
        $sample->delete();

        return response()->json(['message' => 'ok']);
    }

    private function payload(MetricSample $s): array
    {
        return [
            'id' => $s->id,
            'member_id' => $s->member_id,
            'metric_key' => $s->metric_key,
            'value' => $s->value,
            'unit' => $s->unit,
            'measured_at' => ShanghaiTime::format($s->measured_at),
            'source_record_id' => $s->source_record_id,
        ];
    }
}
