<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NyDeviceGroup extends Model
{
    protected $fillable = [
        'ny_panel_id', 'remote_id', 'name', 'type',
        'original_connect_host', 'custom_connect_host',
        'port_range', 'is_enabled', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'remote_id' => 'integer',
            'is_enabled' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(NyPanel::class, 'ny_panel_id');
    }

    /**
     * 对客户暴露的连接主机：优先使用自定义覆盖域名，否则用 NY 原始值。
     */
    public function effectiveHost(): string
    {
        return $this->custom_connect_host ?: ($this->original_connect_host ?: '');
    }
}
