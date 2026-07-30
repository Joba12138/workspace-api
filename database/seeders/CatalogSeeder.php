<?php

namespace Database\Seeders;

use App\Models\MetricDef;
use App\Models\RecordType;
use App\Models\TemplatePack;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $colors = config('workspace.colors.packs');

        $packs = [
            [
                'key' => 'pregnancy',
                'title' => '孕期记录',
                'subtitle' => '孕周、产检、胎动与体重',
                'color' => $colors['pregnancy']['primary'],
                'color_soft' => $colors['pregnancy']['soft'],
                'icon' => 'pregnancy',
                'sort' => 10,
                'config' => ['member_types' => ['fetus', 'self']],
            ],
            [
                'key' => 'newborn',
                'title' => '宝宝成长',
                'subtitle' => '护理、生长、疫苗与大事记',
                'color' => $colors['newborn']['primary'],
                'color_soft' => $colors['newborn']['soft'],
                'icon' => 'newborn',
                'sort' => 20,
                'config' => ['member_types' => ['child', 'fetus'], 'hub' => 'baby'],
            ],
            [
                'key' => 'journal',
                'title' => '日常记录',
                'subtitle' => '随手记、心情与待办碎片',
                'color' => $colors['journal']['primary'],
                'color_soft' => $colors['journal']['soft'],
                'icon' => 'journal',
                'sort' => 30,
                'config' => ['member_types' => ['self', 'partner', 'other']],
            ],
            [
                'key' => 'love',
                'title' => '恋爱日常',
                'subtitle' => '约会、纪念日与甜蜜瞬间（可升级为婚姻）',
                'color' => $colors['love']['primary'],
                'color_soft' => $colors['love']['soft'],
                'icon' => 'love',
                'sort' => 40,
                'config' => ['member_types' => ['self', 'partner'], 'hub' => 'love', 'phases' => ['dating', 'engaged', 'married']],
            ],
        ];

        foreach ($packs as $pack) {
            TemplatePack::updateOrCreate(['key' => $pack['key']], $pack + ['is_public' => true]);
        }

        $types = [
            // pregnancy
            ['key' => 'kick', 'title' => '胎动', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 1, 'schema' => [
                'fields' => [
                    ['key' => 'count', 'label' => '次数', 'type' => 'number', 'required' => true],
                    ['key' => 'duration_min', 'label' => '时长(分)', 'type' => 'number'],
                ],
            ]],
            ['key' => 'checkup', 'title' => '产检', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 2, 'schema' => [
                'fields' => [
                    ['key' => 'hospital', 'label' => '医院', 'type' => 'text'],
                    ['key' => 'week', 'label' => '孕周', 'type' => 'number'],
                    ['key' => 'result', 'label' => '结果摘要', 'type' => 'textarea'],
                ],
            ]],
            ['key' => 'symptom', 'title' => '症状', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 3, 'schema' => [
                'fields' => [
                    ['key' => 'name', 'label' => '症状', 'type' => 'text', 'required' => true],
                    ['key' => 'severity', 'label' => '程度(1-5)', 'type' => 'number'],
                ],
            ]],
            ['key' => 'ultrasound', 'title' => 'B超', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 4, 'schema' => [
                'fields' => [
                    ['key' => 'week', 'label' => '孕周', 'type' => 'number'],
                    ['key' => 'summary', 'label' => '描述', 'type' => 'textarea'],
                ],
            ]],
            ['key' => 'preg_weight', 'title' => '孕期体重', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 5, 'schema' => [
                'fields' => [
                    ['key' => 'weight_kg', 'label' => '体重(kg)', 'type' => 'number', 'required' => true, 'metric' => 'weight'],
                ],
            ]],
            // newborn
            ['key' => 'feeding', 'title' => '吃奶', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 1, 'schema' => [
                'fields' => [
                    ['key' => 'method', 'label' => '方式', 'type' => 'select', 'options' => ['breast', 'bottle', 'mixed'], 'required' => true],
                    ['key' => 'amount_ml', 'label' => '奶量(ml)', 'type' => 'number'],
                    ['key' => 'side', 'label' => '侧', 'type' => 'select', 'options' => ['left', 'right', 'both']],
                    ['key' => 'duration_min', 'label' => '时长(分)', 'type' => 'number'],
                ],
            ]],
            ['key' => 'diaper', 'title' => '尿布', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 2, 'schema' => [
                'fields' => [
                    ['key' => 'kind', 'label' => '类型', 'type' => 'select', 'options' => ['pee', 'poop', 'both'], 'required' => true],
                ],
            ]],
            ['key' => 'sleep', 'title' => '睡眠', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 3, 'schema' => [
                'fields' => [
                    ['key' => 'duration_min', 'label' => '时长(分)', 'type' => 'number', 'required' => true],
                    ['key' => 'quality', 'label' => '质量', 'type' => 'select', 'options' => ['good', 'ok', 'poor']],
                ],
            ]],
            ['key' => 'temperature', 'title' => '体温', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 4, 'schema' => [
                'fields' => [
                    ['key' => 'celsius', 'label' => '体温(℃)', 'type' => 'number', 'required' => true, 'metric' => 'temperature'],
                ],
            ]],
            ['key' => 'jaundice', 'title' => '黄疸', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 5, 'schema' => [
                'fields' => [
                    ['key' => 'value', 'label' => '数值', 'type' => 'number', 'required' => true, 'metric' => 'jaundice'],
                ],
            ]],
            ['key' => 'vaccine', 'title' => '疫苗', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 6, 'schema' => [
                'fields' => [
                    ['key' => 'name', 'label' => '疫苗名', 'type' => 'text', 'required' => true],
                    ['key' => 'site', 'label' => '接种点', 'type' => 'text'],
                ],
            ]],
            ['key' => 'milestone', 'title' => '大事记', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 7, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '事件', 'type' => 'text', 'required' => true],
                    ['key' => 'body', 'label' => '描述', 'type' => 'textarea'],
                ],
            ]],
            ['key' => 'growth_measure', 'title' => '身高体重', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 8, 'schema' => [
                'fields' => [
                    ['key' => 'height_cm', 'label' => '身高(cm)', 'type' => 'number', 'metric' => 'height'],
                    ['key' => 'weight_kg', 'label' => '体重(kg)', 'type' => 'number', 'metric' => 'weight'],
                ],
            ]],
            // journal
            ['key' => 'note', 'title' => '随手记', 'pack_key' => 'journal', 'color' => $colors['journal']['primary'], 'sort' => 1, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                    ['key' => 'body', 'label' => '内容', 'type' => 'textarea', 'required' => true],
                ],
            ]],
            ['key' => 'mood', 'title' => '心情', 'pack_key' => 'journal', 'color' => $colors['journal']['primary'], 'sort' => 2, 'schema' => [
                'fields' => [
                    ['key' => 'score', 'label' => '心情(1-5)', 'type' => 'number', 'required' => true],
                    ['key' => 'tags', 'label' => '标签', 'type' => 'text'],
                ],
            ]],
            ['key' => 'habit_check', 'title' => '习惯打卡', 'pack_key' => 'journal', 'color' => $colors['journal']['primary'], 'sort' => 3, 'schema' => [
                'fields' => [
                    ['key' => 'habit', 'label' => '习惯名', 'type' => 'text', 'required' => true],
                    ['key' => 'done', 'label' => '完成', 'type' => 'select', 'options' => ['yes', 'no'], 'required' => true],
                ],
            ]],
            // love
            ['key' => 'date_night', 'title' => '约会', 'pack_key' => 'love', 'color' => $colors['love']['primary'], 'sort' => 1, 'schema' => [
                'fields' => [
                    ['key' => 'place', 'label' => '地点', 'type' => 'text'],
                    ['key' => 'activity', 'label' => '做了什么', 'type' => 'textarea', 'required' => true],
                    ['key' => 'rating', 'label' => '甜蜜指数(1-5)', 'type' => 'number'],
                ],
            ]],
            ['key' => 'anniversary', 'title' => '纪念日', 'pack_key' => 'love', 'color' => $colors['love']['primary'], 'sort' => 2, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['key' => 'years', 'label' => '第几年', 'type' => 'number'],
                ],
            ]],
            ['key' => 'love_note', 'title' => '甜蜜瞬间', 'pack_key' => 'love', 'color' => $colors['love']['primary'], 'sort' => 3, 'schema' => [
                'fields' => [
                    ['key' => 'body', 'label' => '内容', 'type' => 'textarea', 'required' => true],
                ],
            ]],
            ['key' => 'gift', 'title' => '礼物', 'pack_key' => 'love', 'color' => $colors['love']['primary'], 'sort' => 4, 'schema' => [
                'fields' => [
                    ['key' => 'item', 'label' => '礼物', 'type' => 'text', 'required' => true],
                    ['key' => 'from_to', 'label' => '谁给谁', 'type' => 'text'],
                ],
            ]],
            ['key' => 'wedding_prep', 'title' => '备婚事项', 'pack_key' => 'love', 'color' => $colors['engaged']['primary'] ?? '#C4894A', 'sort' => 5, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '事项', 'type' => 'text', 'required' => true],
                    ['key' => 'status', 'label' => '状态', 'type' => 'text'],
                    ['key' => 'body', 'label' => '备注', 'type' => 'textarea'],
                ],
            ]],
            ['key' => 'wedding', 'title' => '婚礼/仪式', 'pack_key' => 'love', 'color' => $colors['marriage']['primary'] ?? '#7A4E3D', 'sort' => 6, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '仪式名', 'type' => 'text', 'required' => true],
                    ['key' => 'place', 'label' => '地点', 'type' => 'text'],
                    ['key' => 'body', 'label' => '记述', 'type' => 'textarea'],
                ],
            ]],
            ['key' => 'daily_together', 'title' => '一起的日常', 'pack_key' => 'love', 'color' => $colors['marriage']['primary'] ?? '#7A4E3D', 'sort' => 7, 'schema' => [
                'fields' => [
                    ['key' => 'body', 'label' => '今天一起做了什么', 'type' => 'textarea', 'required' => true],
                    ['key' => 'mood', 'label' => '心情(1-5)', 'type' => 'number'],
                ],
            ]],
            ['key' => 'wishlist', 'title' => '心愿清单', 'pack_key' => 'love', 'color' => $colors['love']['primary'], 'sort' => 8, 'schema' => [
                'fields' => [
                    ['key' => 'title', 'label' => '想一起做的事', 'type' => 'text', 'required' => true],
                    ['key' => 'status', 'label' => '状态(想做/计划中/完成)', 'type' => 'text'],
                    ['key' => 'body', 'label' => '备注', 'type' => 'textarea'],
                ],
            ]],
        ];

        foreach ($types as $type) {
            RecordType::updateOrCreate(
                ['key' => $type['key']],
                $type + ['icon' => $type['key'], 'is_active' => true]
            );
        }

        $metrics = [
            ['key' => 'weight', 'title' => '体重', 'unit' => 'kg', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 1],
            ['key' => 'height', 'title' => '身高', 'unit' => 'cm', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 2],
            ['key' => 'temperature', 'title' => '体温', 'unit' => '℃', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 3],
            ['key' => 'jaundice', 'title' => '黄疸值', 'unit' => 'mg/dL', 'pack_key' => 'newborn', 'color' => $colors['newborn']['primary'], 'sort' => 4],
            ['key' => 'fetal_hr', 'title' => '胎心率', 'unit' => 'bpm', 'pack_key' => 'pregnancy', 'color' => $colors['pregnancy']['primary'], 'sort' => 1],
        ];

        foreach ($metrics as $m) {
            MetricDef::updateOrCreate(['key' => $m['key']], $m);
        }
    }
}
