<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WgServer extends Model
{
    protected $fillable = [
        'name', 'endpoint', 'public_key', 'private_key',
        'listen_port', 'server_cidr', 'dns', 'mtu',
        'next_ip_index', 'is_active', 'remark',
        'ssh_host', 'ssh_port', 'ssh_user', 'ssh_private_key', 'role',
    ];

    protected $hidden = ['private_key', 'ssh_private_key'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'ssh_private_key' => 'encrypted',
            'listen_port' => 'integer',
            'ssh_port' => 'integer',
            'mtu' => 'integer',
            'next_ip_index' => 'integer',
            'is_active' => 'integer',
        ];
    }

    public function peers(): HasMany
    {
        return $this->hasMany(RouterDeviceWgPeer::class);
    }

    public function allocateIp(): string
    {
        $cidr = $this->server_cidr;
        $base = explode('/', $cidr)[0];
        $parts = explode('.', $base);

        $index = $this->next_ip_index;
        $this->increment('next_ip_index');

        $parts[2] = intdiv($index, 256);
        $parts[3] = $index % 256;

        return implode('.', $parts) . '/32';
    }
}
