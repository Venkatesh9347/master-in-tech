<?php

namespace App\Jobs;

use App\Mail\StudentLoginOtpMail;
use App\Models\StudentLoginOtp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public string $otp;
    public int $expirySeconds;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 15;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, string $otp, int $expirySeconds = 30)
    {
        $this->userId = $userId;
        $this->otp = $otp;
        $this->expirySeconds = $expirySeconds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // Verify that there is still an active, non-expired OTP record for this user
        $hasActiveOtp = StudentLoginOtp::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->exists();

        if (! $hasActiveOtp) {
            // OTP has already expired, been invalidated, or verified — discard stale job safely
            return;
        }

        try {
            Mail::to($user->email)->send(new StudentLoginOtpMail($this->otp, $user, $this->expirySeconds));
        } catch (\Throwable $e) {
            Log::error('Failed in queued OTP email delivery: ' . $e->getMessage());
            throw $e;
        }
    }
}
