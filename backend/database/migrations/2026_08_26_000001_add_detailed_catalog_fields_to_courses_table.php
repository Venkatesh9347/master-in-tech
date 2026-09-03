<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'thumbnail')) {
                $table->string('thumbnail')->nullable()->after('category');
            }
            if (! Schema::hasColumn('courses', 'full_description')) {
                $table->text('full_description')->nullable()->after('description');
            }
            if (! Schema::hasColumn('courses', 'prerequisites')) {
                $table->json('prerequisites')->nullable()->after('difficulty');
            }
            if (! Schema::hasColumn('courses', 'learning_objectives')) {
                $table->json('learning_objectives')->nullable()->after('prerequisites');
            }
            if (! Schema::hasColumn('courses', 'skills_gained')) {
                $table->json('skills_gained')->nullable()->after('learning_objectives');
            }
            if (! Schema::hasColumn('courses', 'status')) {
                $table->string('status')->default('published')->after('is_published');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $columns = ['thumbnail', 'full_description', 'prerequisites', 'learning_objectives', 'skills_gained', 'status'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('courses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
