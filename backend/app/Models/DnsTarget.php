<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsTarget extends Model
{
    protected $fillable = [
        'name', 'xui_panel_id',
        'cf_zone_id', 'cf_record_id', 'cf_record_name', 'cf_api_token',
        'primary_ip', 'backup_ip', 'current_active',
        'probe_port', 'probe_host',
        'probe_interval_minutes', 'failure_threshold', 'probe_timeout_seconds',
        'probe_vless_url',
        'status', 'consecutive_failures', 'last_probe_at', 'last_switched_at',
        'is_active',
    ];

    protected $hidden = ['cf_api_token', 'probe_vless_url'];

    protected function casts(): array
    {
        return [
            'cf_api_token' => 'encrypted',
            'probe_vless_url' => 'encrypted',
            'consecutive_failures' => 'integer',
            'last_probe_at' => 'datetime',
            'last_switched_at' => 'datetime',
            'is_active' => 'integer',
            'probe_port' => 'integer',
            'probe_interval_minutes' => 'integer',
            'failure_threshold' => 'integer',
            'probe_timeout_seconds' => 'integer',
        ];
    }

    public function xuiPanel(): BelongsTo
    {
        return $this->belongsTo(XuiPanel::class);
    }

    public function probeResults(): HasMany
    {
        return $this->hasMany(DnsProbeResult::class);
    }

    public function failoverEvents(): HasMany
    {
        return $this->hasMany(DnsFailoverEvent::class);
    }

    public function effectiveProbeHost(): string
    {
        return $this->probe_host ?: $this->cf_record_name;
    }

    public function currentIp(): string
    {
        return $this->current_active === 'backup' ? $this->backup_ip : $this->primary_ip;
    }
}
