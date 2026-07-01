<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterWifiAccount extends Model
{
    protected $fillable = [
        'router_device_id', 'username', 'password', 'label',
        'vlan_id', 'ip_prefix', 'gateway_ip',
        'proxy_subscription_id', 'proxy_mode',
        'is_active', 'max_devices', 'ip_start_index',
    ];

    protected function casts(): array
    {
        return [
            'vlan_id' => 'integer',
            'is_active' => 'integer',
            'max_devices' => 'integer',
            'ip_start_index' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(RouterDevice::class, 'router_device_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'proxy_subscription_id');
    }

    public function getAllocatedIps(): array
    {
        if ($this->ip_start_index < 2) {
            return [];
        }
        $ips = [];
        for ($i = 0; $i < $this->max_devices; $i++) {
            $idx = $this->ip_start_index + $i;
            $ips[] = long2ip(ip2long('10.10.0.0') + $idx);
        }
        return $ips;
    }

    public function getSocks5String(): ?string
    {
        $sub = $this->subscription;
        if (!$sub) return null;

        $ip = $sub->proxyIp;
        if (!$ip) return null;

        $forward = $sub->forwardRule;
        if ($forward) {
            $conn = $forward->toDisplayConnection($ip);
            return $conn['socks5'] ?? null;
        }

        return $ip->socks5_info;
    }

    public function getSocks5Parts(): ?array
    {
        $socks5 = $this->getSocks5String();
        if (!$socks5) return null;

        $parts = explode(':', $socks5);
        return [
            'server' => $parts[0] ?? '',
            'port' => (int) ($parts[1] ?? 0),
            'username' => $parts[2] ?? '',
            'password' => $parts[3] ?? '',
        ];
    }
}
