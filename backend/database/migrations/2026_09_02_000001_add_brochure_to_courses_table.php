<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'brochure')) {
                $table->string('brochure', 2000)->nullable()->after('banner');
            }
            if (! Schema::hasColumn('courses', 'brochure_media_id')) {
                $table->foreignId('brochure_media_id')->nullable()->after('brochure')->constrained('media_assets')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'brochure_media_id')) {
                $table->dropForeign(['brochure_media_id']);
                $table->dropColumn('brochure_media_id');
            }
            if (Schema::hasColumn('courses', 'brochure')) {
                $table->dropColumn('brochure');
            }
        });
    }
};
