<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookConfig extends Model
{
    protected $fillable = [
        'name', 'type', 'webhook_url', 'secret_key',
        'events', 'is_active',
    ];

    protected $hidden = ['secret_key'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'integer',
        ];
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
