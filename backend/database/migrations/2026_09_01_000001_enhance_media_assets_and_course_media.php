<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance media_assets
        if (Schema::hasTable('media_assets')) {
            Schema::table('media_assets', function (Blueprint $table) {
                if (! Schema::hasColumn('media_assets', 'original_name')) {
                    $table->string('original_name')->nullable()->after('file_name');
                }
                if (! Schema::hasColumn('media_assets', 'title')) {
                    $table->string('title')->nullable()->after('original_name');
                }
                if (! Schema::hasColumn('media_assets', 'folder')) {
                    $table->string('folder')->default('general')->index()->after('disk');
                }
            });
        }

        // 2. Enhance courses with banner and media_id
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (! Schema::hasColumn('courses', 'banner')) {
                    $table->string('banner', 2000)->nullable()->after('thumbnail');
                }
                if (! Schema::hasColumn('courses', 'media_id')) {
                    $table->foreignId('media_id')->nullable()->after('banner')->constrained('media_assets')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (Schema::hasColumn('courses', 'media_id')) {
                    $table->dropConstrainedForeignId('media_id');
                }
                if (Schema::hasColumn('courses', 'banner')) {
                    $table->dropColumn('banner');
                }
            });
        }

        if (Schema::hasTable('media_assets')) {
            Schema::table('media_assets', function (Blueprint $table) {
                if (Schema::hasColumn('media_assets', 'folder')) {
                    $table->dropColumn('folder');
                }
                if (Schema::hasColumn('media_assets', 'title')) {
                    $table->dropColumn('title');
                }
                if (Schema::hasColumn('media_assets', 'original_name')) {
                    $table->dropColumn('original_name');
                }
            });
        }
    }
};
