<?php

/**
 * 阿里云 OSS（对齐 guanwang-api 配置语义）
 * 环境变量见 .env.example 的 ALIOSS_*
 */
return [
    'stores' => [
        'default' => [
            'access_id' => env('ALIOSS_ACCESS_ID'),
            'access_key' => env('ALIOSS_ACCESS_KEY'),
            'bucket' => env('ALIOSS_BUCKET'),
            'endpoint' => env('ALIOSS_ENDPOINT', 'oss-cn-hangzhou.aliyuncs.com'),
            'cdn_domain' => env('ALIOSS_CDN_DOMAIN'),
            'ssl' => env('ALIOSS_SSL', true),
            'is_domain' => env('ALIOSS_IS_CNAME', true),
        ],
    ],

    'disk_prefix' => 'alioss_',

    /*
    | 业务模块上传策略
    */
    'modules' => [
        'avatar' => [
            'label' => '头像',
            'expire' => 300,
            'min_size' => 1,
            'max_size' => 5 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'kinds' => ['image'],
        ],
        'album' => [
            'label' => '云相册',
            'expire' => 900,
            'min_size' => 1,
            'max_size' => 50 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'mp4', 'mov'],
            'kinds' => ['image', 'video'],
        ],
        'love' => [
            'label' => '恋爱相册',
            'expire' => 900,
            'min_size' => 1,
            'max_size' => 50 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'mp4', 'mov'],
            'kinds' => ['image', 'video'],
        ],
        'record' => [
            'label' => '记录附件',
            'expire' => 600,
            'min_size' => 1,
            'max_size' => 20 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
            'kinds' => ['image', 'file'],
        ],
        'document' => [
            'label' => '文档',
            'expire' => 600,
            'min_size' => 1,
            'max_size' => 20 * 1024 * 1024,
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
            'kinds' => ['file'],
        ],
    ],
];
