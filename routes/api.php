<?php

use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\KinshipController;
use App\Http\Controllers\Api\LoveController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MetricSampleController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Middleware\EnsureWorkspaceAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/apple', [AuthController::class, 'apple']);

    // OSS 回调免登录
    Route::post('attachments/callback', [AttachmentController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);

        Route::middleware(EnsureWorkspaceAccess::class.':read')->group(function () {
            Route::get('home', [HomeController::class, 'show']);

            Route::get('members', [MemberController::class, 'index']);
            Route::get('members/{id}', [MemberController::class, 'show']);

            Route::get('kinship', [KinshipController::class, 'index']);

            Route::get('record-types', [CatalogController::class, 'recordTypes']);
            Route::get('record-schemas/{type}', [CatalogController::class, 'recordSchema']);
            Route::get('metrics', [CatalogController::class, 'metrics']);
            Route::get('template-packs', [CatalogController::class, 'packs']);

            Route::get('records', [RecordController::class, 'index']);
            Route::get('records/{id}', [RecordController::class, 'show']);

            Route::get('reminders', [ReminderController::class, 'index']);

            Route::get('metric-samples', [MetricSampleController::class, 'index']);
            Route::get('metric-samples/series', [MetricSampleController::class, 'series']);

            Route::get('members/{memberId}/vaccine-schedule', [ChecklistController::class, 'vaccineSchedule']);
            Route::get('members/{memberId}/album', [AlbumController::class, 'index']);

            Route::get('love/profile', [LoveController::class, 'profile']);
            Route::get('love/home', [LoveController::class, 'home']);
            Route::get('love/album', [LoveController::class, 'album']);

            Route::get('attachments/modules', [AttachmentController::class, 'modules']);
            Route::get('attachments/{id}', [AttachmentController::class, 'show']);
        });

        Route::middleware(EnsureWorkspaceAccess::class.':write')->group(function () {
            Route::post('home/focus', [HomeController::class, 'setFocus']);

            Route::post('members', [MemberController::class, 'store']);
            Route::patch('members/{id}', [MemberController::class, 'update']);
            Route::delete('members/{id}', [MemberController::class, 'destroy']);
            Route::post('members/{id}/birth', [MemberController::class, 'birth']);
            Route::post('members/{id}/stages', [MemberController::class, 'storeStage']);

            Route::post('kinship', [KinshipController::class, 'store']);
            Route::delete('kinship/{id}', [KinshipController::class, 'destroy']);

            Route::post('template-packs/{key}/install', [CatalogController::class, 'installPack']);
            Route::delete('template-packs/{key}/install', [CatalogController::class, 'uninstallPack']);

            Route::post('love/upgrade', [LoveController::class, 'upgrade']);
            Route::patch('love/theme', [LoveController::class, 'updateTheme']);
            Route::post('love/partner', [LoveController::class, 'bindPartner']);
            Route::delete('love/partner', [LoveController::class, 'unbindPartner']);
            Route::patch('love/dates', [LoveController::class, 'updateDates']);
            Route::post('love/anniversaries', [LoveController::class, 'addAnniversary']);
            Route::delete('love/anniversaries/{id}', [LoveController::class, 'removeAnniversary']);
            Route::patch('love/photos/{id}', [LoveController::class, 'bindPhoto']);

            Route::post('records', [RecordController::class, 'store']);
            Route::patch('records/{id}', [RecordController::class, 'update']);
            Route::delete('records/{id}', [RecordController::class, 'destroy']);
            Route::post('records/{id}/restore', [RecordController::class, 'restore']);

            Route::post('metric-samples', [MetricSampleController::class, 'store']);
            Route::delete('metric-samples/{id}', [MetricSampleController::class, 'destroy']);

            Route::post('checklist-items', [ChecklistController::class, 'storeCustomItem']);
            Route::post('checklist-items/{itemId}/complete', [ChecklistController::class, 'completeItem']);
            Route::post('checklist-items/{itemId}/reset', [ChecklistController::class, 'resetItem']);

            Route::post('attachments/ticket', [AttachmentController::class, 'ticket']);
            Route::post('attachments/upload', [AttachmentController::class, 'upload']);
            Route::patch('attachments/{id}', [AlbumController::class, 'updateMeta']);
            Route::delete('attachments/{id}', [AttachmentController::class, 'destroy']);

            Route::post('reminders', [ReminderController::class, 'store']);
            Route::patch('reminders/{id}', [ReminderController::class, 'update']);
            Route::delete('reminders/{id}', [ReminderController::class, 'destroy']);
            Route::post('reminders/{id}/restore', [ReminderController::class, 'restore']);
        });
    });
});
