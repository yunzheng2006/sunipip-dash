<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpstreamProvider extends Model
{
    protected $fillable = [
        'name', 'remark', 'slug', 'driver', 'api_url',
        'credentials', 'callback_path', 'is_active', 'public_sale', 'extra_config',
    ];

    protected $casts = [
        'credentials'  => 'array',
        'extra_config'  => 'array',
        'is_active'     => 'boolean',
        'public_sale'   => 'boolean',
    ];

    protected $hidden = ['credentials'];

    public function getCallbackUrlAttribute(): string
    {
        $base = rtrim(config('app.url', ''), '/');
        return $base . ($this->callback_path ?: "/api/v1/upstream/{$this->slug}/callback");
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->where('is_active', true)->first();
    }

    public static function driverOptions(): array
    {
        return [
            'spark' => [
                'label'  => 'Spark',
                'fields' => ['supplier_no', 'aes_key', 'version'],
            ],
            'ipipv' => [
                'label'  => 'IPIPV',
                'fields' => ['app_key', 'app_secret'],
            ],
        ];
    }
}
