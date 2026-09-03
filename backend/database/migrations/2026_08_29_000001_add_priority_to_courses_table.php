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
        if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'priority')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->integer('priority')->nullable()->default(100)->after('status');
                $table->index('priority');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'priority')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropIndex(['priority']);
                $table->dropColumn('priority');
            });
        }
    }
};
