<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpipvInstance extends Model
{
    protected $fillable = [
        'ipipv_order_id', 'proxy_ip_id', 'instance_no',
        'ip', 'port', 'username', 'password',
        'product_no', 'country_code', 'city_code', 'protocol',
        'status', 'flow_total', 'flow_balance', 'expire_at',
    ];

    protected $casts = [
        'port'         => 'integer',
        'status'       => 'integer',
        'flow_total'   => 'decimal:2',
        'flow_balance' => 'decimal:2',
        'expire_at'    => 'datetime',
    ];

    public function ipipvOrder(): BelongsTo
    {
        return $this->belongsTo(IpipvOrder::class);
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class);
    }

    public static function mapStatus(int $status): string
    {
        return match ($status) {
            1  => 'pending',
            2  => 'creating',
            3  => 'running',
            6  => 'stopped',
            10 => 'closed',
            11 => 'released',
            default => 'unknown',
        };
    }
}
