<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterConfigSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'router_device_id', 'config_version', 'config_type',
        'payload', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'config_version' => 'integer',
            'created_at' => 'datetime',
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
