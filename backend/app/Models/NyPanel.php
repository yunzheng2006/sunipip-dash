<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NyPanel extends Model
{
    protected $fillable = [
        'name', 'api_url', 'username', 'password',
        'last_token', 'token_expires_at', 'is_active', 'description',
    ];

    protected $hidden = ['password', 'last_token'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'integer',
        ];
    }

    public function deviceGroups(): HasMany
    {
        return $this->hasMany(NyDeviceGroup::class);
    }

    public function forwardRules(): HasMany
    {
        return $this->hasMany(ForwardRule::class);
    }

    /**
     * 规范化 api_url: 去尾部斜杠 + /api/v1 前缀
     */
    public function normalizedBase(): string
    {
        return rtrim($this->api_url, '/') . '/api/v1';
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        // 显式暴露一个 has_token 字段给前端，但不暴露真实 token
        $array['has_token'] = !empty($this->last_token);
        return $array;
    }
}
