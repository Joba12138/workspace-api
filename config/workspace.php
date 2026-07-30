<?php

/**
 * 产品色板与时区约定（前后端共用语义）
 * 时间：Asia/Shanghai，API 序列化为 ISO8601（+08:00）
 */
return [
    'timezone' => 'Asia/Shanghai',

    'colors' => [
        'brand' => [
            'primary' => '#1E3A36',
            'soft' => '#E6F0ED',
            'ink' => '#1C2422',
            'muted' => '#6B7874',
            'surface' => '#F4F7F6',
            'line' => '#D5E0DC',
            'accent' => '#2F6F64',
        ],
        'packs' => [
            'pregnancy' => [
                'primary' => '#C96B7B',
                'soft' => '#F8E8EB',
                'title' => '孕期记录',
            ],
            'newborn' => [
                'primary' => '#3D9AAD',
                'soft' => '#E3F3F6',
                'title' => '新生儿护理',
            ],
            'journal' => [
                'primary' => '#5C6B8A',
                'soft' => '#E8ECF2',
                'title' => '日常记录',
            ],
            'love' => [
                'primary' => '#B8435A',
                'soft' => '#F9E6EC',
                'title' => '恋爱日常',
            ],
            'engaged' => [
                'primary' => '#C4894A',
                'soft' => '#F7EFE4',
                'title' => '备婚日常',
            ],
            'marriage' => [
                'primary' => '#7A4E3D',
                'soft' => '#F3EBE6',
                'title' => '婚姻日常',
            ],
        ],

        // 恋爱 / 备婚 / 婚姻可选主题色预设
        'love_themes' => [
            ['key' => 'berry', 'label' => '莓红恋爱', 'phase' => 'dating', 'primary' => '#B8435A', 'soft' => '#F9E6EC'],
            ['key' => 'blush', 'label' => '桃粉', 'phase' => 'dating', 'primary' => '#D46A8A', 'soft' => '#FBEFF3'],
            ['key' => 'champagne', 'label' => '香槟备婚', 'phase' => 'engaged', 'primary' => '#C4894A', 'soft' => '#F7EFE4'],
            ['key' => 'ivory', 'label' => '象牙金', 'phase' => 'engaged', 'primary' => '#B89A6A', 'soft' => '#F5F0E6'],
            ['key' => 'walnut', 'label' => '胡桃婚姻', 'phase' => 'married', 'primary' => '#7A4E3D', 'soft' => '#F3EBE6'],
            ['key' => 'wine', 'label' => '酒红', 'phase' => 'married', 'primary' => '#8E3B4C', 'soft' => '#F6EAED'],
            ['key' => 'sage', 'label' => '雾绿', 'phase' => 'both', 'primary' => '#5B7A6E', 'soft' => '#EAF1EE'],
            ['key' => 'ink', 'label' => '墨青', 'phase' => 'both', 'primary' => '#3D5A5B', 'soft' => '#E8F0F0'],
        ],
    ],
];
