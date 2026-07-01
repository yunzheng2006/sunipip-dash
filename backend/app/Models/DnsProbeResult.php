<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsProbeResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dns_target_id', 'dns_agent_id',
        'probed_host', 'probed_port',
        'success', 'latency_ms', 'error_message', 'probed_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'probed_at' => 'datetime',
            'latency_ms' => 'integer',
            'probed_port' => 'integer',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(DnsTarget::class, 'dns_target_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(DnsAgent::class, 'dns_agent_id');
    }
}
