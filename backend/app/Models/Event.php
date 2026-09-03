<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'short_description',
        'banner',
        'category',
        'speaker_name',
        'speaker_designation',
        'speaker_image',
        'event_date',
        'start_time',
        'end_time',
        'duration',
        'mode',
        'meeting_url',
        'location',
        'price',
        'registration_limit',
        'registered_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'start_time' => 'string',
            'end_time' => 'string',
            'price' => 'decimal:2',
            'registration_limit' => 'integer',
            'registered_count' => 'integer',
            'duration' => 'integer',
        ];
    }

    /**
     * Get the registrations for this event.
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * Get the registered users for this event.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'event_registrations');
    }

    /**
     * Check if event is full.
     */
    public function isFull(): bool
    {
        if ($this->registration_limit === null) {
            return false;
        }
        return $this->registered_count >= $this->registration_limit;
    }

    /**
     * Check if event is upcoming.
     */
    public function isUpcoming(): bool
    {
        return $this->event_date > now();
    }

    /**
     * Check if event is completed.
     */
    public function isCompleted(): bool
    {
        return $this->event_date < now() || $this->status === 'completed';
    }
}
