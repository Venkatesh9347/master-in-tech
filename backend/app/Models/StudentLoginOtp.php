<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLoginOtp extends Model
{
    use HasFactory;

    protected $table = 'student_login_otps';

    protected $fillable = [
        'user_id',
        'temp_token_hash',
        'otp_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'resend_available_at',
        'verified_at',
    ];

    protected $hidden = [
        'otp_hash',
        'temp_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function isMaxAttemptsReached(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function hasExceededAttempts(): bool
    {
        return $this->isMaxAttemptsReached();
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    public function getRemainingAttempts(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function canResend(): bool
    {
        return now()->isAfter($this->resend_available_at) || now()->equalTo($this->resend_available_at);
    }

    public function getRemainingCooldownSeconds(): int
    {
        if ($this->canResend()) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->resend_available_at, false));
    }
}
