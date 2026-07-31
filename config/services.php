<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Apple Sign In（必须与 iOS Bundle ID / Apple Developer App ID 完全一致）
    | 多个可用英文逗号分隔，例如：top.yanzehnkun.workspace,top.yanzhenkun.workspace
    */
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'top.yanzehnkun.workspace'),
        'client_id_web' => env('APPLE_CLIENT_ID_WEB'),
    ],

    /*
    | UniPush 提醒推送
    | driver=getui：个推 RestAPI（开发者中心 → uni-push → 应用配置 取密钥）
    | driver=unicloud：云函数 URL 化地址（UniPush 2.0 官方路径）
    */
    'unipush' => [
        'driver' => env('UNIPUSH_DRIVER', 'getui'),
        'app_id' => env('UNIPUSH_APP_ID'),
        'app_key' => env('UNIPUSH_APP_KEY'),
        'master_secret' => env('UNIPUSH_MASTER_SECRET'),
        'cloud_url' => env('UNIPUSH_CLOUD_URL'),
        'cloud_secret' => env('UNIPUSH_CLOUD_SECRET'),
    ],

];
