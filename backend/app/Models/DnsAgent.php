<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnsAgent extends Model
{
    protected $fillable = [
        'name', 'agent_key', 'location', 'last_ip', 'last_heartbeat_at',
        'is_active', 'description',
    ];

    protected $hidden = ['agent_key'];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'is_active' => 'integer',
        ];
    }
}
