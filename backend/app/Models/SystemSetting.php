<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);
        $group = count($parts) === 2 ? $parts[0] : 'general';
        $settingKey = count($parts) === 2 ? $parts[1] : $key;

        $setting = static::where('group', $group)->where('key', $settingKey)->first();
        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'integer' => (int) $setting->value,
            'boolean' => (bool) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key, 2);
        $group = count($parts) === 2 ? $parts[0] : 'general';
        $settingKey = count($parts) === 2 ? $parts[1] : $key;

        $setting = static::where('group', $group)->where('key', $settingKey)->first();
        if ($setting) {
            $setting->update([
                'value' => is_array($value) ? json_encode($value) : (string) $value,
            ]);
        }
    }
}
