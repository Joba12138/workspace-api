<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricDef;
use App\Models\MetricSample;
use App\Models\Record;
use App\Models\RecordType;
use App\Support\ShanghaiTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $query = Record::with(['recordType', 'member'])
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('happened_at');

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->string('member_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('types')) {
            $types = is_array($request->input('types'))
                ? $request->input('types')
                : explode(',', (string) $request->input('types'));
            $query->whereIn('type', $types);
        }

        if ($request->filled('pack_key')) {
            $keys = RecordType::where('pack_key', $request->string('pack_key'))->pluck('key');
            $query->whereIn('type', $keys);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        $paginator = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Record $r) => $this->payload($r))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'member_id' => ['required', 'uuid', Rule::exists('members', 'id')->where('workspace_id', $workspaceId)],
            'type' => ['required', 'string', Rule::exists('record_types', 'key')],
            'happened_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
            'note' => ['nullable', 'string'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['client_id'])) {
            $existing = Record::where('workspace_id', $workspaceId)
                ->where('client_id', $data['client_id'])
                ->first();
            if ($existing) {
                return response()->json(['data' => $this->payload($existing->load(['recordType', 'member']))]);
            }
        }

        $record = Record::create([
            'workspace_id' => $workspaceId,
            'member_id' => $data['member_id'],
            'type' => $data['type'],
            'happened_at' => ShanghaiTime::parse($data['happened_at']),
            'payload' => $data['payload'] ?? [],
            'note' => $data['note'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->syncMetricsFromRecord($record, $request->user()->id);

        return response()->json(['data' => $this->payload($record->load(['recordType', 'member']))], 201);
    }

    public function show(Request $request, string $id)
    {
        $record = $this->find($request, $id);

        return response()->json(['data' => $this->payload($record)]);
    }

    public function update(Request $request, string $id)
    {
        $record = $this->find($request, $id);

        $data = $request->validate([
            'happened_at' => ['sometimes', 'date'],
            'payload' => ['sometimes', 'array'],
            'note' => ['nullable', 'string'],
            'member_id' => ['sometimes', 'uuid'],
        ]);

        if (isset($data['happened_at'])) {
            $data['happened_at'] = ShanghaiTime::parse($data['happened_at']);
        }

        $record->fill($data)->save();

        $this->syncMetricsFromRecord($record->fresh(), $request->user()->id);

        return response()->json(['data' => $this->payload($record->fresh()->load(['recordType', 'member']))]);
    }

    public function destroy(Request $request, string $id)
    {
        $record = $this->find($request, $id);
        $record->delete();

        return response()->json(['message' => 'ok']);
    }

    public function restore(Request $request, string $id)
    {
        $record = Record::onlyTrashed()
            ->where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();

        $record->restore();
        $record->deleted_by = null;
        $record->save();

        return response()->json(['data' => $this->payload($record->load(['recordType', 'member']))]);
    }

    private function find(Request $request, string $id): Record
    {
        return Record::with(['recordType', 'member'])
            ->where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();
    }

    /** 从 Record.payload + schema.metric 拆出 MetricSample */
    private function syncMetricsFromRecord(Record $record, int $userId): void
    {
        $type = RecordType::where('key', $record->type)->first();
        $fields = $type?->schema['fields'] ?? [];
        $payload = $record->payload ?? [];

        // 兼容未在 schema 标注 metric 的常见字段
        $fallback = [
            'weight_kg' => 'weight',
            'height_cm' => 'height',
            'celsius' => 'temperature',
            'value' => $record->type === 'jaundice' ? 'jaundice' : null,
        ];

        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            $metricKey = $field['metric'] ?? ($fallback[$key] ?? null);
            if (! $key || ! $metricKey || ! isset($payload[$key]) || $payload[$key] === '' || $payload[$key] === null) {
                continue;
            }
            if (! MetricDef::where('key', $metricKey)->exists()) {
                continue;
            }

            MetricSample::updateOrCreate(
                [
                    'source_record_id' => $record->id,
                    'metric_key' => $metricKey,
                ],
                [
                    'workspace_id' => $record->workspace_id,
                    'member_id' => $record->member_id,
                    'value' => (float) $payload[$key],
                    'unit' => MetricDef::find($metricKey)?->unit ?? '',
                    'measured_at' => $record->happened_at,
                    'created_by' => $userId,
                ]
            );
        }

        // growth_measure 等：schema 无 metric 时走 fallback
        foreach ($fallback as $payloadKey => $metricKey) {
            if (! $metricKey || ! isset($payload[$payloadKey]) || $payload[$payloadKey] === '') {
                continue;
            }
            if (! MetricDef::where('key', $metricKey)->exists()) {
                continue;
            }
            MetricSample::updateOrCreate(
                [
                    'source_record_id' => $record->id,
                    'metric_key' => $metricKey,
                ],
                [
                    'workspace_id' => $record->workspace_id,
                    'member_id' => $record->member_id,
                    'value' => (float) $payload[$payloadKey],
                    'unit' => MetricDef::find($metricKey)?->unit ?? '',
                    'measured_at' => $record->happened_at,
                    'created_by' => $userId,
                ]
            );
        }
    }

    private function payload(Record $r): array
    {
        return [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'member_name' => $r->member?->name,
            'type' => $r->type,
            'type_title' => $r->recordType?->title,
            'type_color' => $r->recordType?->color,
            'pack_key' => $r->recordType?->pack_key,
            'happened_at' => ShanghaiTime::format($r->happened_at),
            'payload' => $r->payload,
            'note' => $r->note,
            'client_id' => $r->client_id,
            'created_at' => ShanghaiTime::format($r->created_at),
            'deleted_at' => ShanghaiTime::format($r->deleted_at),
        ];
    }
}
