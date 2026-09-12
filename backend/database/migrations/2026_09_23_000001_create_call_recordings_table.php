<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider-independent call recordings for CRM telephony.
     *
     * Supports provider-hosted references (URL + provider recording id),
     * direct uploads to private storage, and future telephony webhooks.
     * Files always live on a private disk; playback goes through an
     * authorized controller endpoint, never a public URL.
     */
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->nullable()->constrained('enquiries')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Customer/student account when known')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->comment('Telecaller/advisor who handled the call')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->comment('Staff member the call task was assigned to')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('call_started_at')->nullable();
            $table->timestamp('call_ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('direction', 16)->default('outbound'); // inbound, outbound
            $table->string('outcome', 64)->nullable(); // interested, not_interested, callback, converted, no_answer, busy, failed
            $table->text('notes')->nullable();
            $table->foreignId('follow_up_id')->nullable()->constrained('crm_follow_ups')->nullOnDelete();
            $table->string('recording_status', 32)->default('none'); // none, processing, ready, failed
            $table->string('recording_provider', 64)->nullable(); // exotel, twilio, upload, ...
            $table->string('recording_reference', 255)->nullable()->comment('Provider-side recording id or URL');
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 128)->nullable()->comment('SHA-256 of the stored file');
            $table->string('processing_state', 32)->default('none'); // none, pending, processed, failed
            $table->timestamps();

            $table->index(['enquiry_id', 'created_at']);
            $table->index(['handled_by', 'created_at']);
            $table->index('recording_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
