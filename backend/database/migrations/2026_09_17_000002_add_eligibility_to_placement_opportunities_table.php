<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('placement_opportunities') && ! Schema::hasColumn('placement_opportunities', 'eligibility')) {
            Schema::table('placement_opportunities', function (Blueprint $table) {
                $table->text('eligibility')->nullable()->after('experience_required');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('placement_opportunities') && Schema::hasColumn('placement_opportunities', 'eligibility')) {
            Schema::table('placement_opportunities', function (Blueprint $table) {
                $table->dropColumn('eligibility');
            });
        }
    }
};
