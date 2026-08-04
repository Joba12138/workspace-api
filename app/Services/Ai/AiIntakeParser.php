<?php

namespace App\Services\Ai;

use App\Contracts\LlmClient;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class AiIntakeParser
{
    public function __construct(
        protected LlmClient $llm,
        protected RecordTypeSkillCatalog $catalog,
    ) {}

    /**
     * @return array{
     *   draft: array,
     *   skill: ?array,
     *   missing_fields: list<string>,
     *   model: ?string
     * }
     */
    public function parse(string $workspaceId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('请输入要记录的内容');
        }

        $catalog = $this->catalog->forWorkspace($workspaceId);
        if ($catalog['skills'] === []) {
            throw new RuntimeException('当前空间尚未安装可用的记录类型');
        }

        $now = Carbon::now('Asia/Shanghai');
        $system = $this->systemPrompt();
        $user = $this->userPrompt($text, $catalog, $now);

        $result = $this->llm->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], [
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        $raw = $result->json();
        if (! is_array($raw)) {
            throw new RuntimeException('没听懂，请再说具体一点');
        }

        $draft = $this->normalizeDraft($raw, $catalog, $now);
        $skill = $this->catalog->findSkill($catalog, (string) $draft['type']);
        if (! $skill) {
            throw new RuntimeException('没匹配到合适的记录类型，请换个说法或手动选择表单');
        }

        $missing = $this->missingRequiredFields($skill, $draft['payload'] ?? []);

        return [
            'draft' => $draft,
            'skill' => $skill,
            'missing_fields' => $missing,
            'members' => $catalog['members'],
            'model' => $result->model,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
你是「人生工作台」的智能录入助手。根据用户一句话，从给定 skills 中选出最匹配的一种记录类型，并抽取字段。
硬性规则：
1. 只能从 skills 列表的 type 中选择，禁止编造 type。
2. 只输出一个 JSON 对象，不要 markdown，不要解释。
3. payload 的 key 必须来自该 skill 的 fields；select 字段值尽量落在 options 内。
4. 无法确定的字段用 null，不要瞎编数字。
5. member_id 必须来自 members 列表的 id；不确定则 member_id=null，并给 member_hint（如「宝宝」「自己」「伴侣」）。
6. happened_at 用 ISO8601（带时区）；用户未提及时用当前时间。
7. suggested_stage 只能是 stages 列表的 key，或 null。
8. confidence 为 0~1；summary 为一句中文摘要。
JSON 形状：
{"type":"","member_id":null,"member_hint":"","happened_at":null,"payload":{},"note":"","suggested_stage":null,"confidence":0.0,"summary":""}
PROMPT;
    }

    private function userPrompt(string $text, array $catalog, Carbon $now): string
    {
        $payload = [
            'now' => $now->toIso8601String(),
            'timezone' => 'Asia/Shanghai',
            'user_text' => $text,
            'skills' => $catalog['skills'],
            'members' => $catalog['members'],
            'stages' => $catalog['stages'],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeDraft(array $raw, array $catalog, Carbon $now): array
    {
        $type = (string) ($raw['type'] ?? '');
        $payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
        $cleanPayload = [];
        foreach ($payload as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $cleanPayload[(string) $k] = $v;
        }

        $memberId = $raw['member_id'] ?? null;
        $memberId = is_string($memberId) && $memberId !== '' ? $memberId : null;
        $memberHint = is_string($raw['member_hint'] ?? null) ? trim((string) $raw['member_hint']) : '';

        $validMemberIds = collect($catalog['members'])->pluck('id')->all();
        if ($memberId && ! in_array($memberId, $validMemberIds, true)) {
            $memberId = null;
        }
        if (! $memberId) {
            $memberId = $this->resolveMemberId($catalog['members'], $memberHint, $type);
        }

        $happenedAt = $this->normalizeHappenedAt($raw['happened_at'] ?? null, $now);

        $stageKeys = collect($catalog['stages'])->pluck('key')->all();
        $suggested = $raw['suggested_stage'] ?? null;
        if (! is_string($suggested) || ! in_array($suggested, $stageKeys, true)) {
            $suggested = null;
        }

        $confidence = $raw['confidence'] ?? 0;
        if (! is_numeric($confidence)) {
            $confidence = 0;
        }
        $confidence = max(0, min(1, (float) $confidence));

        $summary = trim((string) ($raw['summary'] ?? ''));
        if ($summary === '') {
            $summary = Str::limit($raw['note'] ?? $type, 40, '');
        }

        return [
            'type' => $type,
            'member_id' => $memberId,
            'member_hint' => $memberHint,
            'happened_at' => $happenedAt,
            'payload' => $cleanPayload,
            'note' => trim((string) ($raw['note'] ?? '')) ?: null,
            'suggested_stage' => $suggested,
            'confidence' => $confidence,
            'summary' => $summary,
        ];
    }

    private function normalizeHappenedAt(mixed $value, Carbon $now): string
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->timezone('Asia/Shanghai')->toIso8601String();
            } catch (\Throwable) {
                // fallthrough
            }
        }

        return $now->toIso8601String();
    }

    /**
     * @param  list<array{id:string,name:?string,nickname:?string,type:?string}>  $members
     */
    private function resolveMemberId(array $members, string $hint, string $type): ?string
    {
        if ($members === []) {
            return null;
        }

        $hintLower = mb_strtolower($hint);
        foreach ($members as $m) {
            $name = mb_strtolower((string) ($m['name'] ?? ''));
            $nick = mb_strtolower((string) ($m['nickname'] ?? ''));
            if ($hint !== '' && ($hintLower === $name || $hintLower === $nick || str_contains($name, $hintLower) || str_contains($nick, $hintLower))) {
                return $m['id'];
            }
        }

        $preferTypes = [];
        if (preg_match('/宝宝|孩子|婴儿|宝贝/u', $hint) || in_array($type, ['feeding', 'diaper', 'sleep', 'temperature', 'jaundice', 'vaccine', 'milestone', 'growth_measure'], true)) {
            $preferTypes = ['child', 'fetus'];
        } elseif (preg_match('/孕|胎/u', $hint) || in_array($type, ['kick', 'checkup', 'symptom', 'ultrasound', 'preg_weight'], true)) {
            $preferTypes = ['fetus', 'self'];
        } elseif (preg_match('/伴侣|老公|老婆|对象|另一半/u', $hint)) {
            $preferTypes = ['partner'];
        } elseif (preg_match('/自己|我/u', $hint)) {
            $preferTypes = ['self'];
        }

        foreach ($preferTypes as $t) {
            foreach ($members as $m) {
                if (($m['type'] ?? null) === $t) {
                    return $m['id'];
                }
            }
        }

        foreach ($members as $m) {
            if (($m['type'] ?? null) === 'self') {
                return $m['id'];
            }
        }

        return $members[0]['id'] ?? null;
    }

    /**
     * @return list<string>
     */
    private function missingRequiredFields(array $skill, array $payload): array
    {
        $missing = [];
        foreach ($skill['fields'] ?? [] as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $key = $field['key'] ?? null;
            if (! $key) {
                continue;
            }
            $val = $payload[$key] ?? null;
            if ($val === null || $val === '') {
                $missing[] = (string) ($field['label'] ?? $key);
            }
        }

        return $missing;
    }
}
