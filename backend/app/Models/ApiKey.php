<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiKey extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'key', 'secret', 'scopes', 'price_markup', 'rate_limit',
        'is_active', 'expires_at', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'price_markup' => 'decimal:2',
            'rate_limit' => 'integer',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected $hidden = ['secret'];

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function hasScope(string $scope): bool
    {
        if (empty($this->scopes)) return true; // 空=全部允许
        return in_array($scope, $this->scopes);
    }

    public static function generateKey(): string
    {
        return 'sk_' . bin2hex(random_bytes(16));
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
