<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SparkInstance extends Model
{
    protected $fillable = [
        'spark_order_id', 'proxy_ip_id', 'instance_id', 'ip', 'port',
        'username', 'password', 'type', 'use_type', 'status',
        'flow', 'balance_flow', 'expire_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'type' => 'integer',
            'use_type' => 'integer',
            'status' => 'integer',
            'flow' => 'integer',
            'balance_flow' => 'integer',
            'expire_at' => 'datetime',
        ];
    }

    public function sparkOrder(): BelongsTo
    {
        return $this->belongsTo(SparkOrder::class);
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class);
    }
}
