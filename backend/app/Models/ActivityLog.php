<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id',
        'description', 'properties', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 记录系统日志（非用户操作，如cron任务、自动过期等）
     */
    public static function system(string $action, string $description, array $properties = []): void
    {
        static::create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => 'System',
            'description' => $description,
            'properties' => $properties,
            'ip_address' => 'system',
            'created_at' => now(),
        ]);
    }
}
