<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_pack_installations', function (Blueprint $table) {
            // dating | married（目前主要用于 love pack）
            $table->string('phase', 20)->nullable()->after('pack_key');
            $table->string('display_title')->nullable()->after('phase');
            $table->string('color', 20)->nullable()->after('display_title');
            $table->string('color_soft', 20)->nullable()->after('color');
            $table->uuid('partner_member_id')->nullable()->after('color_soft');
            $table->timestamp('phase_changed_at')->nullable()->after('partner_member_id');
            $table->json('meta')->nullable()->after('phase_changed_at');

            $table->foreign('partner_member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('template_pack_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_member_id');
            $table->dropColumn([
                'phase',
                'display_title',
                'color',
                'color_soft',
                'phase_changed_at',
                'meta',
            ]);
        });
    }
};
