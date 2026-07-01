<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterDeviceWgPeer extends Model
{
    protected $fillable = [
        'router_device_id', 'wg_server_id',
        'peer_public_key', 'peer_private_key', 'assigned_ip',
        'preshared_key', 'persistent_keepalive', 'is_active',
    ];

    protected $hidden = ['peer_private_key'];

    protected function casts(): array
    {
        return [
            'peer_private_key' => 'encrypted',
            'persistent_keepalive' => 'integer',
            'is_active' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(RouterDevice::class, 'router_device_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(WgServer::class, 'wg_server_id');
    }
}
