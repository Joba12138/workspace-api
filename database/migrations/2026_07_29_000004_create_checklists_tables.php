<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('member_id')->nullable();
            $table->string('key', 60); // vaccine_cn_infant
            $table->string('title');
            $table->string('pack_key', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->unique(['workspace_id', 'member_id', 'key'], 'checklists_member_key_unique');
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('checklist_id');
            $table->uuid('workspace_id');
            $table->string('title');
            $table->unsignedSmallInteger('dose_no')->nullable();
            $table->unsignedSmallInteger('dose_total')->nullable();
            $table->boolean('is_free')->default(true);
            $table->unsignedSmallInteger('age_months')->nullable(); // 推荐月龄
            $table->date('recommended_on')->nullable();
            $table->string('status', 20)->default('pending'); // pending|done|skipped
            $table->timestamp('done_at')->nullable();
            $table->uuid('source_record_id')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('checklist_id')->references('id')->on('checklists')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('source_record_id')->references('id')->on('records')->nullOnDelete();
            $table->index(['checklist_id', 'age_months']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
    }
};
