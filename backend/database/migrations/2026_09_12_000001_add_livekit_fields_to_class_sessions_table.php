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
        Schema::table('class_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('class_sessions', 'livekit_room_name')) {
                $table->string('livekit_room_name')->nullable()->unique()->after('meeting_password');
            }
            if (! Schema::hasColumn('class_sessions', 'livekit_status')) {
                $table->string('livekit_status', 20)->default('idle')->after('livekit_room_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'livekit_status')) {
                $table->dropColumn('livekit_status');
            }
            if (Schema::hasColumn('class_sessions', 'livekit_room_name')) {
                $table->dropColumn('livekit_room_name');
            }
        });
    }
};
