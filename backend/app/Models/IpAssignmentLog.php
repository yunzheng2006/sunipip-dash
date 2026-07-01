<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpAssignmentLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'proxy_ip_id', 'customer_id', 'subscription_id',
        'action', 'operated_by', 'remark', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operated_by');
    }
}
