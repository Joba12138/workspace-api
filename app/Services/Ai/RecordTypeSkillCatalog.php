<?php

namespace App\Services\Ai;

use App\Models\LifeStageDef;
use App\Models\Member;
use App\Models\RecordType;
use App\Models\TemplatePackInstallation;

class RecordTypeSkillCatalog
{
    /**
     * @return array{skills: list<array>, members: list<array>, stages: list<array>}
     */
    public function forWorkspace(string $workspaceId): array
    {
        $installedPacks = TemplatePackInstallation::where('workspace_id', $workspaceId)
            ->pluck('pack_key');

        $skills = RecordType::query()
            ->where('is_active', true)
            ->whereIn('pack_key', $installedPacks)
            ->orderBy('sort')
            ->get()
            ->map(fn (RecordType $t) => $this->skillFromType($t))
            ->values()
            ->all();

        $members = Member::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->get(['id', 'name', 'nickname', 'type'])
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'nickname' => $m->nickname,
                'type' => $m->type,
            ])
            ->values()
            ->all();

        $stages = LifeStageDef::query()
            ->where('is_core', true)
            ->orderBy('sort')
            ->get(['key', 'title', 'primary_pack'])
            ->map(fn (LifeStageDef $s) => [
                'key' => $s->key,
                'title' => $s->title,
                'primary_pack' => $s->primary_pack,
            ])
            ->values()
            ->all();

        return compact('skills', 'members', 'stages');
    }

    public function skillFromType(RecordType $t): array
    {
        $fields = [];
        foreach (($t->schema['fields'] ?? []) as $field) {
            if (! is_array($field) || empty($field['key'])) {
                continue;
            }
            $row = [
                'key' => (string) $field['key'],
                'label' => (string) ($field['label'] ?? $field['key']),
                'type' => (string) ($field['type'] ?? 'text'),
                'required' => (bool) ($field['required'] ?? false),
            ];
            if (! empty($field['options']) && is_array($field['options'])) {
                $row['options'] = array_values($field['options']);
            }
            $fields[] = $row;
        }

        return [
            'type' => $t->key,
            'title' => $t->title,
            'pack_key' => $t->pack_key,
            'fields' => $fields,
        ];
    }

    public function findSkill(array $catalog, string $type): ?array
    {
        foreach ($catalog['skills'] as $skill) {
            if (($skill['type'] ?? null) === $type) {
                return $skill;
            }
        }

        return null;
    }
}
