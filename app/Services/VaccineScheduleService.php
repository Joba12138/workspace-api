<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Member;
use Carbon\Carbon;

class VaccineScheduleService
{
    /** 简化国家免疫规划（与参考 App 月龄节点对齐，可后续配置化） */
    public function template(): array
    {
        return [
            ['age_months' => 1, 'title' => '乙肝疫苗', 'dose_no' => 2, 'dose_total' => 3, 'is_free' => true, 'sort' => 10],
            ['age_months' => 2, 'title' => '脊髓灰质炎疫苗', 'dose_no' => 1, 'dose_total' => 4, 'is_free' => true, 'sort' => 20],
            ['age_months' => 2, 'title' => '百白破疫苗', 'dose_no' => 1, 'dose_total' => 5, 'is_free' => true, 'sort' => 21],
            ['age_months' => 3, 'title' => '脊髓灰质炎疫苗', 'dose_no' => 2, 'dose_total' => 4, 'is_free' => true, 'sort' => 30],
            ['age_months' => 4, 'title' => '脊髓灰质炎疫苗', 'dose_no' => 3, 'dose_total' => 4, 'is_free' => true, 'sort' => 40],
            ['age_months' => 4, 'title' => '百白破疫苗', 'dose_no' => 2, 'dose_total' => 5, 'is_free' => true, 'sort' => 41],
            ['age_months' => 5, 'title' => '百白破疫苗', 'dose_no' => 3, 'dose_total' => 5, 'is_free' => true, 'sort' => 50],
            ['age_months' => 6, 'title' => '乙肝疫苗', 'dose_no' => 3, 'dose_total' => 3, 'is_free' => true, 'sort' => 60],
            ['age_months' => 6, 'title' => '脊髓灰质炎疫苗', 'dose_no' => 4, 'dose_total' => 4, 'is_free' => true, 'sort' => 61],
            ['age_months' => 8, 'title' => '麻腮风疫苗', 'dose_no' => 1, 'dose_total' => 2, 'is_free' => true, 'sort' => 70],
            ['age_months' => 8, 'title' => '乙脑减毒活疫苗', 'dose_no' => 1, 'dose_total' => 2, 'is_free' => true, 'sort' => 71],
            ['age_months' => 18, 'title' => '百白破疫苗', 'dose_no' => 4, 'dose_total' => 5, 'is_free' => true, 'sort' => 80],
            ['age_months' => 18, 'title' => '麻腮风疫苗', 'dose_no' => 2, 'dose_total' => 2, 'is_free' => true, 'sort' => 81],
        ];
    }

    public function ensureForMember(string $workspaceId, Member $member): Checklist
    {
        $checklist = Checklist::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'member_id' => $member->id,
                'key' => 'vaccine_cn_infant',
            ],
            [
                'title' => '接种表',
                'pack_key' => 'newborn',
            ]
        );

        if ($checklist->items()->exists()) {
            $this->refreshRecommendedDates($checklist, $member);

            return $checklist->load('items');
        }

        $born = $this->bornDate($member);

        foreach ($this->template() as $row) {
            ChecklistItem::create([
                'checklist_id' => $checklist->id,
                'workspace_id' => $workspaceId,
                'title' => $row['title'],
                'dose_no' => $row['dose_no'],
                'dose_total' => $row['dose_total'],
                'is_free' => $row['is_free'],
                'age_months' => $row['age_months'],
                'recommended_on' => $born
                    ? $born->copy()->addMonthsNoOverflow($row['age_months'])->toDateString()
                    : null,
                'status' => 'pending',
                'sort' => $row['sort'],
            ]);
        }

        return $checklist->load('items');
    }

    public function refreshRecommendedDates(Checklist $checklist, Member $member): void
    {
        $born = $this->bornDate($member);
        if (! $born) {
            return;
        }

        foreach ($checklist->items as $item) {
            if ($item->age_months === null) {
                continue;
            }
            $item->recommended_on = $born->copy()->addMonthsNoOverflow($item->age_months)->toDateString();
            $item->save();
        }
    }

    protected function bornDate(Member $member): ?Carbon
    {
        if ($member->born_at) {
            return Carbon::parse($member->born_at)->timezone('Asia/Shanghai')->startOfDay();
        }
        if ($member->birthday) {
            return Carbon::parse($member->birthday)->timezone('Asia/Shanghai')->startOfDay();
        }

        return null;
    }
}
