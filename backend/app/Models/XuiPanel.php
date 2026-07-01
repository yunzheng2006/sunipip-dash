<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class XuiPanel extends Model
{
    protected $fillable = [
        'name', 'api_url', 'username', 'password',
        'connect_host', 'session_cookie', 'cookie_expires_at',
        'is_active', 'mirror_panel_id', 'is_mirror', 'description',
    ];

    protected $hidden = ['password', 'session_cookie'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'session_cookie' => 'encrypted',
            'cookie_expires_at' => 'datetime',
            'is_active' => 'integer',
            'is_mirror' => 'integer',
            'mirror_panel_id' => 'integer',
        ];
    }

    public function inbounds(): HasMany
    {
        return $this->hasMany(XuiInbound::class);
    }

    /**
     * 备机面板（一对一）
     */
    public function mirror()
    {
        return $this->belongsTo(XuiPanel::class, 'mirror_panel_id');
    }

    /**
     * 去除 api_url 尾部斜杠，返回 base URL
     * 例：https://vps.example.com:54321/abc123
     */
    public function normalizedBase(): string
    {
        return rtrim($this->api_url, '/');
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        $array['has_cookie'] = !empty($this->session_cookie);
        return $array;
    }
}
