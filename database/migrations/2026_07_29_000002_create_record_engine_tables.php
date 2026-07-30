<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_types', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('title');
            $table->string('pack_key', 40)->nullable()->index();
            $table->string('icon')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->json('schema'); // fields definition
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('member_id');
            $table->string('type'); // record_types.key
            $table->timestamp('happened_at');
            $table->json('payload');
            $table->text('note')->nullable();
            $table->uuid('client_id')->nullable(); // idempotency
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('type')->references('key')->on('record_types');
            $table->unique(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'member_id', 'happened_at']);
            $table->index(['workspace_id', 'type', 'happened_at']);
        });

        Schema::create('metric_defs', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('title');
            $table->string('unit', 20);
            $table->string('pack_key', 40)->nullable()->index();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('metric_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('member_id');
            $table->string('metric_key');
            $table->decimal('value', 12, 3);
            $table->string('unit', 20);
            $table->timestamp('measured_at');
            $table->uuid('source_record_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('metric_key')->references('key')->on('metric_defs');
            $table->foreign('source_record_id')->references('id')->on('records')->nullOnDelete();
            $table->index(['workspace_id', 'member_id', 'metric_key', 'measured_at'], 'metric_samples_member_metric_time_idx');
        });

        Schema::create('template_packs', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('color', 20);
            $table->string('color_soft', 20);
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_public')->default(true);
            $table->json('config')->nullable(); // record_types, metrics, default reminders
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('template_pack_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('pack_key');
            $table->timestamp('installed_at');
            $table->foreignId('installed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('pack_key')->references('key')->on('template_packs');
            $table->unique(['workspace_id', 'pack_key']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('member_id')->nullable();
            $table->string('title');
            $table->timestamp('due_at');
            $table->json('recurrence')->nullable();
            $table->string('related_type', 40)->nullable();
            $table->string('related_key')->nullable();
            $table->string('status', 20)->default('pending'); // pending|done|dismissed
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->index(['workspace_id', 'due_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('template_pack_installations');
        Schema::dropIfExists('template_packs');
        Schema::dropIfExists('metric_samples');
        Schema::dropIfExists('metric_defs');
        Schema::dropIfExists('records');
        Schema::dropIfExists('record_types');
    }
};
