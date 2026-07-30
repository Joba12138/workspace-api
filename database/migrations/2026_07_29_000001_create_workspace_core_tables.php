<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20); // owner|editor|viewer
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->string('type', 30); // self|partner|child|fetus|elder|pet|other
            $table->string('gender', 20)->nullable();
            $table->date('birthday')->nullable();
            $table->timestamp('born_at')->nullable(); // fetus -> child
            $table->string('avatar_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('member_id');
            $table->string('kind', 40); // pregnancy|newborn|infant|adult|love|custom...
            $table->string('title')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->json('meta')->nullable(); // e.g. due_date, lmp_date
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->index(['member_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
        Schema::dropIfExists('members');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('workspaces');
    }
};
