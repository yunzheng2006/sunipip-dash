<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RouterDevice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'device_no', 'serial_number', 'hostname',
        'router_model_id', 'ap_model_id', 'bundle_id',
        'customer_id', 'bound_module', 'bound_at',
        'status', 'install_token', 'install_token_expires_at',
        'agent_key', 'agent_version', 'target_agent_version', 'wan_ip',
        'wg_ip_1', 'wg_ip_2', 'wg_server_1_id', 'wg_server_2_id',
        'config_version', 'applied_config_version',
        'last_heartbeat_at', 'last_heartbeat_ip', 'system_info',
        'ap_management_enabled', 'ap_ip', 'ap_config', 'ap_discovery', 'ap_discover_requested',
        'wifi_ip_next_index', 'wifi_version', 'wifi_max_devices_per_account', 'remark',
    ];

    protected $hidden = ['agent_key', 'install_token'];

    protected function casts(): array
    {
        return [
            'system_info' => 'array',
            'bound_at' => 'datetime',
            'install_token_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'config_version' => 'integer',
            'applied_config_version' => 'integer',
            'ap_management_enabled' => 'boolean',
            'ap_config' => 'array',
            'ap_discovery' => 'array',
            'ap_discover_requested' => 'boolean',
            'wifi_ip_next_index' => 'integer',
            'wifi_version' => 'integer',
            'wifi_max_devices_per_account' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function routerModel(): BelongsTo
    {
        return $this->belongsTo(RouterModel::class);
    }

    public function apModel(): BelongsTo
    {
        return $this->belongsTo(ApModel::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(RouterBundle::class);
    }

    public static function generateDeviceNo(): string
    {
        do {
            $no = 'SunIPIP-' . str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        } while (static::where('device_no', $no)->exists());

        return $no;
    }

    public function wifiAccounts(): HasMany
    {
        return $this->hasMany(RouterWifiAccount::class);
    }

    public function wgPeers(): HasMany
    {
        return $this->hasMany(RouterDeviceWgPeer::class);
    }

    public function wgServer1(): BelongsTo
    {
        return $this->belongsTo(WgServer::class, 'wg_server_1_id');
    }

    public function wgServer2(): BelongsTo
    {
        return $this->belongsTo(WgServer::class, 'wg_server_2_id');
    }

    public function configSnapshots(): HasMany
    {
        return $this->hasMany(RouterConfigSnapshot::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(RouterEventLog::class);
    }

    public function isOnline(): bool
    {
        return $this->last_heartbeat_at && $this->last_heartbeat_at->gt(now()->subMinutes(5));
    }

    public function isConfigSynced(): bool
    {
        return $this->applied_config_version >= $this->config_version;
    }

    public function bumpConfigVersion(): int
    {
        $this->increment('config_version');
        return $this->fresh()->config_version;
    }

    public function scopeOnline($query)
    {
        return $query->where('last_heartbeat_at', '>', now()->subMinutes(5));
    }

    public function scopeOffline($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_heartbeat_at')
              ->orWhere('last_heartbeat_at', '<=', now()->subMinutes(5));
        })->whereNotIn('status', ['inventory', 'decommissioned']);
    }

    public function scopeUnbound($query)
    {
        return $query->whereNull('customer_id');
    }
}
