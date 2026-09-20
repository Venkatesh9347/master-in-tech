<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_url',
        'secret',
        'events',
        'is_active',
    ];

    /**
     * The secret is never serialized to API responses.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Whether this subscription wants a given event name.
     */
    public function wants(string $event): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $events = $this->events ?? [];

        return in_array($event, $events, true);
    }
}
