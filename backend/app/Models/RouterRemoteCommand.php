<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterRemoteCommand extends Model
{
    protected $fillable = [
        'router_device_id', 'command', 'timeout', 'status',
        'exit_code', 'output', 'created_by', 'sent_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'timeout' => 'integer',
            'exit_code' => 'integer',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(RouterDevice::class, 'router_device_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
