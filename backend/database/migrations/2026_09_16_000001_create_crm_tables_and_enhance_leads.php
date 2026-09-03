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
        // 1. Enhance enquiries table with CRM fields
        Schema::table('enquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('enquiries', 'source')) {
                $table->string('source')->default('website')->after('phone');
            }
            if (! Schema::hasColumn('enquiries', 'priority')) {
                $table->string('priority')->default('medium')->after('source'); // hot, warm, cold / high, medium, low
            }
            if (! Schema::hasColumn('enquiries', 'assigned_counsellor_id')) {
                $table->foreignId('assigned_counsellor_id')->nullable()->after('assigned_agent')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('enquiries', 'next_follow_up_date')) {
                $table->date('next_follow_up_date')->nullable()->after('demo_outcome');
            }
            if (! Schema::hasColumn('enquiries', 'next_follow_up_time')) {
                $table->string('next_follow_up_time')->nullable()->after('next_follow_up_date');
            }
            if (! Schema::hasColumn('enquiries', 'qualification')) {
                $table->string('qualification')->nullable()->after('message');
            }
            if (! Schema::hasColumn('enquiries', 'experience_level')) {
                $table->string('experience_level')->nullable()->after('qualification');
            }
            if (! Schema::hasColumn('enquiries', 'city')) {
                $table->string('city')->nullable()->after('experience_level');
            }
            if (! Schema::hasColumn('enquiries', 'expected_revenue')) {
                $table->decimal('expected_revenue', 10, 2)->nullable()->after('city');
            }
            if (! Schema::hasColumn('enquiries', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->default(0.00)->after('expected_revenue');
            }
            if (! Schema::hasColumn('enquiries', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->after('amount_paid'); // unpaid, partial, paid
            }
            if (! Schema::hasColumn('enquiries', 'lost_reason')) {
                $table->text('lost_reason')->nullable()->after('payment_status');
            }

            $table->index('source');
            $table->index('priority');
            $table->index('next_follow_up_date');
            $table->index(['status', 'created_at']);
        });

        // 2. Create crm_activities table (Lead Timeline)
        if (! Schema::hasTable('crm_activities')) {
            Schema::create('crm_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enquiry_id')->constrained('enquiries')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('activity_type'); // note, call, follow_up, demo_scheduled, demo_completed, status_change, payment_event, conversion
                $table->string('title');
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['enquiry_id', 'created_at']);
                $table->index('activity_type');
            });
        }

        // 3. Create crm_follow_ups table (Follow-Up Management)
        if (! Schema::hasTable('crm_follow_ups')) {
            Schema::create('crm_follow_ups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enquiry_id')->constrained('enquiries')->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('scheduled_at');
                $table->string('status')->default('pending'); // pending, completed, cancelled, overdue
                $table->string('title');
                $table->text('notes')->nullable();
                $table->text('outcome')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index('enquiry_id');
                $table->index(['assigned_to', 'status', 'scheduled_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_follow_ups');
        Schema::dropIfExists('crm_activities');

        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropForeign(['assigned_counsellor_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['next_follow_up_date']);
            $table->dropIndex(['status', 'created_at']);

            $table->dropColumn([
                'source',
                'priority',
                'assigned_counsellor_id',
                'next_follow_up_date',
                'next_follow_up_time',
                'qualification',
                'experience_level',
                'city',
                'expected_revenue',
                'amount_paid',
                'payment_status',
                'lost_reason',
            ]);
        });
    }
};
