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
        Schema::table('student_placement_eligibilities', function (Blueprint $table) {
            $table->string('dashboard_status', 30)->default('DISABLED')->after('placement_eligible');
            $table->text('dashboard_status_reason')->nullable()->after('dashboard_status');
            $table->foreignId('dashboard_status_updated_by')->nullable()->after('dashboard_status_reason')->constrained('users', 'id', 'spe_dashboard_status_updated_by_fk')->nullOnDelete();
            $table->timestamp('dashboard_status_updated_at')->nullable()->after('dashboard_status_updated_by');
            $table->timestamp('dashboard_enabled_at')->nullable()->after('dashboard_status_updated_at');
            $table->foreignId('dashboard_enabled_by')->nullable()->after('dashboard_enabled_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_placement_eligibilities', function (Blueprint $table) {
            $table->dropForeign('spe_dashboard_status_updated_by_fk');
            $table->dropForeign(['dashboard_enabled_by']);
            $table->dropColumn([
                'dashboard_status',
                'dashboard_status_reason',
                'dashboard_status_updated_by',
                'dashboard_status_updated_at',
                'dashboard_enabled_at',
                'dashboard_enabled_by',
            ]);
        });
    }
};
