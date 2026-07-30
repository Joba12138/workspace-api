<?php

namespace App\Services;

use App\Models\KinshipEdge;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * 绝对亲属边 → 相对称呼（以 viewer 为中心；可选 focus 临时改视角，如宝宝栏目）
 */
class KinshipLabeler
{
    public function __construct(
        protected string $workspaceId,
    ) {}

    /**
     * @return array{label: string, relation_path: string, via: string|null}
     */
    public function label(Member $viewer, Member $target, ?Member $focus = null): array
    {
        if ($viewer->id === $target->id) {
            return ['label' => '我', 'relation_path' => 'self', 'via' => null];
        }

        $ego = $focus ?: $viewer;
        $edges = $this->edges();

        // 配偶
        if ($this->areSpouses($edges, $ego->id, $target->id)) {
            return [
                'label' => $this->spouseLabel($target),
                'relation_path' => 'spouse',
                'via' => null,
            ];
        }

        // 子女
        if ($this->isParentOf($edges, $ego->id, $target->id)) {
            return [
                'label' => $this->childLabel($target),
                'relation_path' => 'child',
                'via' => null,
            ];
        }

        // 父母
        if ($this->isParentOf($edges, $target->id, $ego->id)) {
            return [
                'label' => $this->parentLabel($target, paternal: true),
                'relation_path' => 'parent',
                'via' => null,
            ];
        }

        // 配偶的父母（公公婆婆 / 岳父岳母）
        $spouseId = $this->spouseOf($edges, $ego->id);
        if ($spouseId && $this->isParentOf($edges, $target->id, $spouseId)) {
            $viaSpouse = Member::find($spouseId);
            $paternalForSpouse = true; // 简化：配偶父母统一用姻亲称谓

            return [
                'label' => $this->inLawParentLabel($ego, $target),
                'relation_path' => 'spouse_parent',
                'via' => $viaSpouse?->name,
            ];
        }

        // 子女的配偶（儿媳 / 女婿）
        $children = $this->childrenOf($edges, $ego->id);
        foreach ($children as $childId) {
            if ($this->areSpouses($edges, $childId, $target->id)) {
                $child = Member::find($childId);

                return [
                    'label' => $this->childInLawLabel($target),
                    'relation_path' => 'child_spouse',
                    'via' => $child?->name,
                ];
            }
        }

        // 祖父母：父母的父母（以 ego 视角；宝宝栏目 focus=宝宝时即爷爷奶奶）
        $parents = $this->parentsOf($edges, $ego->id);
        foreach ($parents as $parentId) {
            if ($this->isParentOf($edges, $target->id, $parentId)) {
                $parent = Member::find($parentId);
                $throughFather = $this->isMale($parent);

                return [
                    'label' => $this->grandparentLabel($target, throughFather: $throughFather),
                    'relation_path' => 'grandparent',
                    'via' => $parent?->name,
                ];
            }
        }

        // 孙辈
        foreach ($children as $childId) {
            if ($this->isParentOf($edges, $childId, $target->id)) {
                return [
                    'label' => $this->grandchildLabel($target),
                    'relation_path' => 'grandchild',
                    'via' => null,
                ];
            }
        }

        return [
            'label' => $target->nickname ?: $target->name,
            'relation_path' => 'other',
            'via' => null,
        ];
    }

    /** @return Collection<int, KinshipEdge> */
    protected function edges(): Collection
    {
        return KinshipEdge::query()
            ->where('workspace_id', $this->workspaceId)
            ->get();
    }

    protected function areSpouses(Collection $edges, string $a, string $b): bool
    {
        return $edges->contains(fn (KinshipEdge $e) => $e->relation === 'spouse'
            && (($e->from_member_id === $a && $e->to_member_id === $b)
                || ($e->from_member_id === $b && $e->to_member_id === $a)));
    }

    protected function isParentOf(Collection $edges, string $parentId, string $childId): bool
    {
        return $edges->contains(fn (KinshipEdge $e) => $e->relation === 'parent'
            && $e->from_member_id === $parentId
            && $e->to_member_id === $childId);
    }

    protected function spouseOf(Collection $edges, string $id): ?string
    {
        $edge = $edges->first(fn (KinshipEdge $e) => $e->relation === 'spouse'
            && ($e->from_member_id === $id || $e->to_member_id === $id));

        if (! $edge) {
            return null;
        }

        return $edge->from_member_id === $id ? $edge->to_member_id : $edge->from_member_id;
    }

    /** @return list<string> */
    protected function childrenOf(Collection $edges, string $parentId): array
    {
        return $edges->where('relation', 'parent')
            ->where('from_member_id', $parentId)
            ->pluck('to_member_id')
            ->all();
    }

    /** @return list<string> */
    protected function parentsOf(Collection $edges, string $childId): array
    {
        return $edges->where('relation', 'parent')
            ->where('to_member_id', $childId)
            ->pluck('from_member_id')
            ->all();
    }

    protected function isMale(?Member $m): bool
    {
        return $m && in_array($m->gender, ['male', 'm', '男'], true);
    }

    protected function isFemale(?Member $m): bool
    {
        return $m && in_array($m->gender, ['female', 'f', '女'], true);
    }

    protected function spouseLabel(Member $t): string
    {
        if ($this->isMale($t)) {
            return '老公';
        }
        if ($this->isFemale($t)) {
            return '老婆';
        }

        return '伴侣';
    }

    protected function childLabel(Member $t): string
    {
        if ($this->isMale($t)) {
            return '儿子';
        }
        if ($this->isFemale($t)) {
            return '女儿';
        }
        if ($t->type === 'fetus') {
            return '宝宝';
        }

        return '孩子';
    }

    protected function parentLabel(Member $t, bool $paternal): string
    {
        if ($this->isMale($t)) {
            return '爸爸';
        }
        if ($this->isFemale($t)) {
            return '妈妈';
        }

        return '父母';
    }

    /** 我看配偶的父母 */
    protected function inLawParentLabel(Member $viewer, Member $target): string
    {
        // 简化：女性视角→公婆；男性视角→岳父岳母
        $viewerFemale = $this->isFemale($viewer);
        if ($viewerFemale) {
            return $this->isMale($target) ? '公公' : ($this->isFemale($target) ? '婆婆' : '公婆');
        }

        return $this->isMale($target) ? '岳父' : ($this->isFemale($target) ? '岳母' : '岳父母');
    }

    protected function childInLawLabel(Member $t): string
    {
        if ($this->isFemale($t)) {
            return '儿媳';
        }
        if ($this->isMale($t)) {
            return '女婿';
        }

        return '孩子配偶';
    }

    protected function grandparentLabel(Member $t, bool $throughFather): string
    {
        if ($throughFather) {
            return $this->isMale($t) ? '爷爷' : ($this->isFemale($t) ? '奶奶' : '祖父母');
        }

        return $this->isMale($t) ? '外公' : ($this->isFemale($t) ? '外婆' : '外祖父母');
    }

    protected function grandchildLabel(Member $t): string
    {
        if ($this->isMale($t)) {
            return '孙子';
        }
        if ($this->isFemale($t)) {
            return '孙女';
        }

        return '孙辈';
    }
}
