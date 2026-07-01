<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpAssetGroup extends Model
{
    protected $fillable = [
        'name', 'source_type', 'source_name', 'country_code',
        'country_name', 'city', 'description', 'api_config',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_config' => 'array',
            'status' => 'integer',
        ];
    }

    public function proxyIps(): HasMany
    {
        return $this->hasMany(ProxyIp::class, 'asset_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importLogs(): HasMany
    {
        return $this->hasMany(IpImportLog::class, 'asset_group_id');
    }

    public function availableIpCount(): int
    {
        return $this->proxyIps()->where('status', 'available')->count();
    }
}
