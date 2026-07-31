<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apple 一键登录：绑定 apple_id，允许无密码 / 无邮箱账号。
 * （仅新增迁移，不改已有迁移文件）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id', 191)->nullable()->unique()->after('id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL');
            DB::statement('ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL');
        } elseif (DB::getDriverName() === 'sqlite') {
            // sqlite 测试环境：原列为可空性较弱，跳过强制修改
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
                $table->string('password')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
