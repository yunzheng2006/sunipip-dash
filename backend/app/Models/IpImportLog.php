<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpImportLog extends Model
{
    protected $fillable = [
        'asset_group_id', 'source_type', 'file_name', 'file_path',
        'total_count', 'success_count', 'fail_count', 'error_details',
        'status', 'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'error_details' => 'array',
        ];
    }

    public function assetGroup(): BelongsTo
    {
        return $this->belongsTo(IpAssetGroup::class, 'asset_group_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function proxyIps(): HasMany
    {
        return $this->hasMany(ProxyIp::class, 'import_batch_id');
    }
}
