<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemConfig extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    public static function get(string $key, $default = null)
    {
        return Cache::remember("sys_config:{$key}", 300, function () use ($key, $default) {
            $config = static::where('key', $key)->first();
            if (!$config) return $default;
            return match ($config->type) {
                'boolean' => (bool) $config->value,
                'integer' => (int) $config->value,
                'json' => json_decode($config->value, true),
                default => $config->value,
            };
        });
    }

    public static function set(string $key, $value, string $type = 'string', ?string $group = null, ?string $description = null): void
    {
        $val = $type === 'json' ? json_encode($value) : (string) $value;
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $val, 'type' => $type, 'group' => $group ?? 'general', 'description' => $description]
        );
        Cache::forget("sys_config:{$key}");
    }
}
