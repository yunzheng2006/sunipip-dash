<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XuiInbound extends Model
{
    protected $fillable = [
        'xui_panel_id', 'proxy_ip_id', 'subscription_id',
        'remote_inbound_id', 'mirror_remote_id',
        'remark', 'port', 'protocol',
        'client_uuid', 'private_key', 'public_key', 'short_id', 'server_name', 'flow',
        'outbound_tag',
        'status', 'batch_id', 'error_message', 'last_synced_at',
        'release_status', 'release_error', 'released_at',
        'mirror_sync_status', 'mirror_sync_error', 'mirror_synced_at',
    ];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return [
            'remote_inbound_id' => 'integer',
            'mirror_remote_id' => 'integer',
            'port' => 'integer',
            'last_synced_at' => 'datetime',
            'mirror_synced_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(XuiPanel::class, 'xui_panel_id');
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * 构造对客 vless:// 连接串（用于前端复制或展示）
     */
    public function buildVlessUrl(?string $connectHost = null): string
    {
        $host = $connectHost ?: ($this->panel?->connect_host ?: parse_url($this->panel?->api_url ?? '', PHP_URL_HOST));
        if (!$host || !$this->client_uuid || !$this->port) {
            return '';
        }

        $params = http_build_query([
            'type' => 'tcp',
            'security' => 'reality',
            'pbk' => $this->public_key,
            'fp' => 'chrome',
            'sni' => $this->server_name ?: 'www.intel.com',
            'sid' => $this->short_id ?: '',
            'spx' => '/',
            'flow' => $this->flow ?: '',
        ]);

        return sprintf(
            'vless://%s@%s:%d?%s#%s',
            $this->client_uuid,
            $host,
            $this->port,
            $params,
            rawurlencode($this->remark)
        );
    }
}
