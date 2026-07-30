<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('disk', 32)->default('alioss_default');
            $table->string('bucket_name', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('module', 64); // album|avatar|record|document|video
            $table->string('kind', 20)->default('image'); // image|video|file
            $table->string('file_name');
            $table->string('file_path', 512);
            $table->string('md5_key', 64)->nullable()->index();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); // 视频秒数
            $table->string('extension', 32)->nullable();
            $table->string('guard', 32)->default('app');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // 业务挂载（可选）
            $table->uuid('member_id')->nullable()->index(); // 云相册主体
            $table->nullableUuidMorphs('attachable'); // Record / ChecklistItem ...
            $table->timestamp('captured_at')->nullable()->index(); // 拍摄/发生时间
            $table->unsignedInteger('day_age')->nullable(); // 相对宝宝日龄
            $table->string('note')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->index(['workspace_id', 'module', 'member_id']);
            $table->index(['workspace_id', 'md5_key', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
