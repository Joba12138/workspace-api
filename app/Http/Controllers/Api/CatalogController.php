<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricDef;
use App\Models\RecordType;
use App\Models\TemplatePack;
use App\Models\TemplatePackInstallation;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function recordTypes(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $installedPacks = TemplatePackInstallation::where('workspace_id', $workspaceId)
            ->pluck('pack_key');

        $query = RecordType::query()->where('is_active', true)->orderBy('sort');

        if ($request->boolean('installed_only', true)) {
            $query->whereIn('pack_key', $installedPacks);
        }

        if ($request->filled('pack_key')) {
            $query->where('pack_key', $request->string('pack_key'));
        }

        $data = $query->get()->map(fn (RecordType $t) => [
            'key' => $t->key,
            'title' => $t->title,
            'pack_key' => $t->pack_key,
            'icon' => $t->icon,
            'color' => $t->color,
            'sort' => $t->sort,
            'schema' => $t->schema,
        ]);

        return response()->json(['data' => $data]);
    }

    public function recordSchema(string $type)
    {
        $t = RecordType::where('key', $type)->firstOrFail();

        return response()->json([
            'data' => [
                'key' => $t->key,
                'title' => $t->title,
                'pack_key' => $t->pack_key,
                'icon' => $t->icon,
                'color' => $t->color,
                'schema' => $t->schema,
            ],
        ]);
    }

    public function metrics(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $installedPacks = TemplatePackInstallation::where('workspace_id', $workspaceId)->pluck('pack_key');

        $query = MetricDef::query()->orderBy('sort');
        if ($request->boolean('installed_only', true)) {
            $query->whereIn('pack_key', $installedPacks);
        }

        return response()->json([
            'data' => $query->get()->map(fn (MetricDef $m) => [
                'key' => $m->key,
                'title' => $m->title,
                'unit' => $m->unit,
                'pack_key' => $m->pack_key,
                'color' => $m->color,
            ]),
        ]);
    }

    public function packs(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $installs = TemplatePackInstallation::where('workspace_id', $workspaceId)
            ->get()
            ->keyBy('pack_key');

        $data = TemplatePack::orderBy('sort')->get()->map(function (TemplatePack $p) use ($installs) {
            $install = $installs->get($p->key);
            $pres = $install?->resolvePresentation($p);

            return [
                'key' => $p->key,
                'title' => $pres['title'] ?? $p->title,
                'subtitle' => $pres['subtitle'] ?? $p->subtitle,
                'color' => $pres['color'] ?? $p->color,
                'color_soft' => $pres['color_soft'] ?? $p->color_soft,
                'icon' => $p->icon,
                'installed' => (bool) $install,
                'phase' => $pres['phase'] ?? null,
                'hub' => $p->key === 'love' ? 'love' : ($p->config['hub'] ?? null),
                'config' => $p->config,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function installPack(Request $request, string $key)
    {
        $workspaceId = $request->attributes->get('workspace_id');
        TemplatePack::where('key', $key)->firstOrFail();

        $row = TemplatePackInstallation::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('pack_key', $key)
            ->first();

        if ($row) {
            if ($row->trashed()) {
                $row->restore();
                $row->deleted_by = null;
                $row->installed_at = now();
                $row->installed_by = $request->user()->id;
            }
            if ($key === 'love' && ! $row->phase) {
                $row->phase = 'dating';
            }
            $row->save();
        } else {
            TemplatePackInstallation::create([
                'workspace_id' => $workspaceId,
                'pack_key' => $key,
                'phase' => $key === 'love' ? 'dating' : null,
                'installed_at' => now(),
                'installed_by' => $request->user()->id,
            ]);
        }

        return response()->json(['message' => 'ok']);
    }

    public function uninstallPack(Request $request, string $key)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $row = TemplatePackInstallation::where('workspace_id', $workspaceId)
            ->where('pack_key', $key)
            ->firstOrFail();

        $row->delete();

        return response()->json(['message' => 'ok']);
    }
}
