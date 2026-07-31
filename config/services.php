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

    /*
    | 企业微信群机器人 Webhook（全局异常告警）
    */
    'wecom' => [
        'enabled' => (bool) env('WECOM_WEBHOOK_ENABLED', true),
        'webhook_url' => env('WECOM_WEBHOOK_URL'),
        // 同一异常类+文案：默认 5 分钟内只报 1 次
        'throttle_seconds' => (int) env('WECOM_WEBHOOK_THROTTLE', 300),
        // 任意异常：默认 60 秒内全局最多 1 条（防止一页 15 个接口同时炸）
        'global_throttle_seconds' => (int) env('WECOM_WEBHOOK_GLOBAL_THROTTLE', 60),
        'dont_report' => [
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
            \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
            \Illuminate\Session\TokenMismatchException::class,
        ],
    ],

];
