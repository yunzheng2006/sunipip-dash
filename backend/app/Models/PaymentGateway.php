<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    protected $fillable = [
        'name', 'type', 'config', 'is_active', 'sort', 'description',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class, 'gateway_id');
    }

    /**
     * 对前端隐藏敏感字段（key/secret），但 id/name/type/methods 可以暴露给客户端
     */
    public function toPublicArray(): array
    {
        $methods = $this->config['methods'] ?? [];
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'methods' => $methods,
            'description' => $this->description,
        ];
    }
}
