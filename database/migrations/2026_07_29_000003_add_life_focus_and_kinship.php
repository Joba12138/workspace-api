<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('linked_user_id')->nullable()->after('workspace_id')
                ->constrained('users')->nullOnDelete();
            $table->string('nickname')->nullable()->after('name');
            $table->index(['workspace_id', 'linked_user_id']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->string('focus_stage_kind', 40)->nullable()->after('role');
            // love|trying|pregnancy|parenting|daily|custom...
        });

        Schema::create('kinship_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('from_member_id');
            $table->uuid('to_member_id');
            // 绝对关系：from 是 to 的 parent / spouse / sibling
            $table->string('relation', 20); // parent|spouse|sibling
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('from_member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('to_member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->unique(['workspace_id', 'from_member_id', 'to_member_id', 'relation'], 'kinship_unique_edge');
            $table->index(['workspace_id', 'to_member_id']);
        });

        Schema::create('life_stage_defs', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('primary_pack')->nullable(); // 首页主推 pack
            $table->json('pack_keys')->nullable(); // 相关 packs
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_core')->default(true); // 主轴；false=后置自定义
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_stage_defs');
        Schema::dropIfExists('kinship_edges');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('focus_stage_kind');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_user_id');
            $table->dropColumn('nickname');
        });
    }
};
