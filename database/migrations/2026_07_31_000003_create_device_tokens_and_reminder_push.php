<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 设备推送标识绑定（UniPush clientId）+ 提醒推送状态
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('push_client_id', 128);
            $table->string('platform', 20)->nullable(); // ios|android|harmony
            $table->string('device_model', 80)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('push_client_id');
            $table->index(['user_id', 'last_seen_at']);
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable()->after('status');
            $table->unsignedTinyInteger('push_attempts')->default(0)->after('pushed_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `device_tokens` COMMENT = '用户设备 UniPush clientId 绑定'");
        }
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn(['pushed_at', 'push_attempts']);
        });
        Schema::dropIfExists('device_tokens');
    }
};
