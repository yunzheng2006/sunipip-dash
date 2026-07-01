<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsFailoverEvent extends Model
{
    protected $fillable = [
        'dns_target_id', 'action', 'from_ip', 'to_ip', 'trigger',
        'triggered_by_user_id', 'reason', 'cf_response', 'success',
    ];

    protected function casts(): array
    {
        return [
            'cf_response' => 'array',
            'success' => 'boolean',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(DnsTarget::class, 'dns_target_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
