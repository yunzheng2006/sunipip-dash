<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthAccessToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'jti', 'client_id', 'customer_id', 'scopes',
        'expires_at', 'revoked_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
