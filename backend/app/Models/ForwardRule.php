<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForwardRule extends Model
{
    protected $fillable = [
        'subscription_id', 'proxy_ip_id', 'ny_panel_id', 'ny_device_group_id',
        'remote_rule_id', 'name', 'dest_host', 'dest_port', 'listen_port',
        'speed_limit_mbps', 'forward_fee', 'forward_plan_id',
        'traffic_used_bytes', 'traffic_limit_bytes', 'overage_charged',
        'status', 'batch_id', 'error_message', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'remote_rule_id' => 'integer',
            'dest_port' => 'integer',
            'listen_port' => 'integer',
            'speed_limit_mbps' => 'integer',
            'forward_fee' => 'decimal:2',
            'last_synced_at' => 'datetime',
        ];
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        $map = [
            'forwardPlan' => 'forward_plan',
            'deviceGroup' => 'device_group',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $array)) {
                $array[$to] = $array[$from];
                unset($array[$from]);
            }
        }
        return $array;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class)->withTrashed();
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(NyPanel::class, 'ny_panel_id');
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(NyDeviceGroup::class, 'ny_device_group_id');
    }

    public function forwardPlan(): BelongsTo
    {
        return $this->belongsTo(ForwardPlan::class);
    }

    /**
     * 给前端的展示字段：拼出完整的对客 socks5 连接串
     *
     * 格式: {effective_host}:{listen_port}:{auth_user}:{auth_pass}
     */
    public function toDisplayConnection(?ProxyIp $ip = null): array
    {
        $planHost = $this->forwardPlan?->display_host;
        $host = $planHost ?: $this->deviceGroup?->effectiveHost();
        $ip ??= $this->proxyIp;
        return [
            'host' => $host,
            'port' => $this->listen_port,
            'username' => $ip?->auth_username,
            'password' => $ip?->auth_password,
            'socks5' => $host && $this->listen_port
                ? implode(':', array_filter([
                    $host,
                    $this->listen_port,
                    $ip?->auth_username,
                    $ip?->auth_password,
                ]))
                : null,
        ];
    }
}
