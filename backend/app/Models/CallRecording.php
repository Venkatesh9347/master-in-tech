<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallRecording extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_NONE = 'none';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'enquiry_id',
        'user_id',
        'handled_by',
        'assigned_to',
        'call_started_at',
        'call_ended_at',
        'duration_seconds',
        'direction',
        'outcome',
        'notes',
        'follow_up_id',
        'recording_status',
        'recording_provider',
        'recording_reference',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum',
        'processing_state',
    ];

    protected function casts(): array
    {
        return [
            'call_started_at' => 'datetime',
            'call_ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(CrmFollowUp::class, 'follow_up_id');
    }

    /**
     * Record-level visibility mirroring lead scoping: admins see all;
     * scoped staff see recordings they handled, assigned to them, or on
     * their visible leads.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user || ! $user->hasScopedCrmAccess()) {
            return $query;
        }

        return $query->where(function ($w) use ($user) {
            $w->where('handled_by', $user->id)
                ->orWhere('assigned_to', $user->id)
                ->orWhereHas('enquiry', fn ($q) => $q->visibleTo($user));
        });
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user || ! $user->hasScopedCrmAccess()) {
            return true;
        }

        if ((int) ($this->handled_by ?? 0) === (int) $user->id) {
            return true;
        }
        if ((int) ($this->assigned_to ?? 0) === (int) $user->id) {
            return true;
        }

        return $this->enquiry ? $this->enquiry->isVisibleTo($user) : true;
    }
}
