<?php

namespace App\Automation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomationExecution extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_id',
        'handler',
        'entity_type',
        'entity_id',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
        ];
    }
}
