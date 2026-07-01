<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterEventLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'router_device_id', 'event_type', 'severity',
        'message', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(RouterDevice::class, 'router_device_id');
    }
}
