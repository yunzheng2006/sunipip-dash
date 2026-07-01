<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $fillable = [
        'webhook_config_id', 'event_type', 'channel', 'title',
        'content', 'related_type', 'related_id', 'status',
        'response', 'retry_count', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function webhookConfig(): BelongsTo
    {
        return $this->belongsTo(WebhookConfig::class);
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        if (array_key_exists('webhookConfig', $array)) {
            $array['webhook_config'] = $array['webhookConfig'];
            unset($array['webhookConfig']);
        }
        return $array;
    }
}
