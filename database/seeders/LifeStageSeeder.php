<?php

namespace Database\Seeders;

use App\Models\LifeStageDef;
use Illuminate\Database\Seeder;

class LifeStageSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'key' => 'love',
                'title' => '恋爱中',
                'subtitle' => '关注约会、纪念日与甜蜜瞬间',
                'primary_pack' => 'love',
                'pack_keys' => ['love', 'journal'],
                'sort' => 10,
                'is_core' => true,
            ],
            [
                'key' => 'engaged',
                'title' => '备婚中',
                'subtitle' => '准备婚礼、清单与重要节点',
                'primary_pack' => 'love',
                'pack_keys' => ['love', 'journal'],
                'sort' => 12,
                'is_core' => true,
            ],
            [
                'key' => 'married',
                'title' => '婚姻中',
                'subtitle' => '共同生活、纪念日与陪伴',
                'primary_pack' => 'love',
                'pack_keys' => ['love', 'journal'],
                'sort' => 15,
                'is_core' => true,
            ],
            [
                'key' => 'trying',
                'title' => '备孕',
                'subtitle' => '为迎接新生命做准备',
                'primary_pack' => 'pregnancy',
                'pack_keys' => ['pregnancy', 'love', 'journal'],
                'sort' => 20,
                'is_core' => true,
            ],
            [
                'key' => 'pregnancy',
                'title' => '孕期',
                'subtitle' => '产检、胎动与孕期变化',
                'primary_pack' => 'pregnancy',
                'pack_keys' => ['pregnancy', 'journal'],
                'sort' => 30,
                'is_core' => true,
            ],
            [
                'key' => 'parenting',
                'title' => '育儿中',
                'subtitle' => '宝宝成长、护理与家人协作',
                'primary_pack' => 'newborn',
                'pack_keys' => ['newborn', 'journal'],
                'sort' => 40,
                'is_core' => true,
            ],
            [
                'key' => 'daily',
                'title' => '日常',
                'subtitle' => '平稳期的随手记与心情',
                'primary_pack' => 'journal',
                'pack_keys' => ['journal'],
                'sort' => 50,
                'is_core' => true,
            ],
        ];

        foreach ($rows as $row) {
            LifeStageDef::updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
