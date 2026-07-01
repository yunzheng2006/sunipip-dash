<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthAuthorizationCode extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code', 'client_id', 'customer_id', 'redirect_uri', 'scopes',
        'code_challenge', 'code_challenge_method', 'nonce',
        'expires_at', 'used_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
