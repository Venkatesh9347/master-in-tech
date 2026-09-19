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
        // 1. Add moderation columns to class_sessions if missing
        Schema::table('class_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('class_sessions', 'current_host_id')) {
                $table->foreignId('current_host_id')->nullable()->after('tutor_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('class_sessions', 'is_chat_enabled')) {
                $table->boolean('is_chat_enabled')->default(false)->after('livekit_status');
            }
        });

        // 2. Add moderation columns to live_classroom_sessions if missing
        if (Schema::hasTable('live_classroom_sessions')) {
            Schema::table('live_classroom_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('live_classroom_sessions', 'current_host_id')) {
                    $table->foreignId('current_host_id')->nullable()->after('tutor_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('live_classroom_sessions', 'is_chat_enabled')) {
                    $table->boolean('is_chat_enabled')->default(false)->after('status');
                }
            });
        }

        // 3. Create classroom_participants table
        if (! Schema::hasTable('classroom_participants')) {
            Schema::create('classroom_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->cascadeOnDelete();
                $table->foreignId('live_classroom_session_id')->nullable()->constrained('live_classroom_sessions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 20)->default('participant'); // host, co-host, participant
                $table->boolean('is_host_active')->default(false);
                $table->boolean('is_mic_allowed')->default(false);
                $table->boolean('is_camera_allowed')->default(false);
                $table->boolean('is_chat_allowed')->default(true);
                $table->boolean('is_hand_raised')->default(false);
                $table->timestamp('hand_raised_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->integer('duration_seconds')->default(0);
                $table->string('connection_state', 30)->default('connected');
                $table->timestamps();

                $table->index(['class_session_id', 'user_id']);
                $table->index(['live_classroom_session_id', 'user_id']);
            });
        }

        // 4. Create classroom_permission_requests table (for Raise Hand)
        if (! Schema::hasTable('classroom_permission_requests')) {
            Schema::create('classroom_permission_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->cascadeOnDelete();
                $table->foreignId('live_classroom_session_id')->nullable()->constrained('live_classroom_sessions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 30)->default('speak'); // speak, camera, chat
                $table->string('status', 30)->default('pending'); // pending, approved, denied, cancelled
                $table->timestamp('requested_at')->useCurrent();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['class_session_id', 'status']);
                $table->index(
                    ['live_classroom_session_id', 'status'],
                    'classroom_permission_requests_session_status_index'
                );
            });
        }

        // 5. Create classroom_messages table (Classroom Chat)
        if (! Schema::hasTable('classroom_messages')) {
            Schema::create('classroom_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->cascadeOnDelete();
                $table->foreignId('live_classroom_session_id')->nullable()->constrained('live_classroom_sessions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('message');
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();

                $table->index(['class_session_id', 'created_at']);
                $table->index(['live_classroom_session_id', 'created_at']);
            });
        }

        // 6. Create classroom_moderation_events table
        if (! Schema::hasTable('classroom_moderation_events')) {
            Schema::create('classroom_moderation_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->cascadeOnDelete();
                $table->foreignId('live_classroom_session_id')->nullable()->constrained('live_classroom_sessions')->cascadeOnDelete();
                $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 50); // mute_student, unmute_student, disable_camera, enable_camera, transfer_host, approve_hand_raise, deny_hand_raise, lower_hand, toggle_chat, end_classroom
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['class_session_id', 'created_at']);
                $table->index(
                    ['live_classroom_session_id', 'created_at'],
                    'classroom_moderation_events_session_created_at_index'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classroom_moderation_events');
        Schema::dropIfExists('classroom_messages');
        Schema::dropIfExists('classroom_permission_requests');
        Schema::dropIfExists('classroom_participants');

        if (Schema::hasTable('live_classroom_sessions')) {
            Schema::table('live_classroom_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('live_classroom_sessions', 'is_chat_enabled')) {
                    $table->dropColumn('is_chat_enabled');
                }
                if (Schema::hasColumn('live_classroom_sessions', 'current_host_id')) {
                    $table->dropConstrainedForeignId('current_host_id');
                }
            });
        }

        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('class_sessions', 'is_chat_enabled')) {
                    $table->dropColumn('is_chat_enabled');
                }
                if (Schema::hasColumn('class_sessions', 'current_host_id')) {
                    $table->dropConstrainedForeignId('current_host_id');
                }
            });
        }
    }
};
