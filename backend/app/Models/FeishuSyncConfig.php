<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeishuSyncConfig extends Model
{
    protected $fillable = [
        'name', 'customer_id',
        'app_id', 'app_secret',
        'app_token', 'real_app_token', 'table_id', 'view_id',
        'field_mapping',
        'is_active', 'synced_count', 'last_synced_at', 'last_sync_error',
        'cached_token', 'token_expires_at',
        'description',
    ];

    protected $hidden = ['app_secret', 'cached_token'];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'cached_token' => 'encrypted',
            'field_mapping' => 'array',
            'is_active' => 'integer',
            'synced_count' => 'integer',
            'last_synced_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * 默认字段映射（平台字段名 → 飞书列名）
     * 基于凯慕传媒的表结构
     */
    public static function defaultFieldMapping(): array
    {
        return [
            'socks5_raw' => '代理socks5',          // 源 socks5 串（主键）
            'socks5_forwarded' => '直连socks5',     // 转发后的 socks5 串
            'socks5_url' => '直连链接',              // socks:// URL
            'qr_image' => '直连二维码',              // Attachment：转发后二维码 PNG
            'label' => '标签',                       // 资产名 / 备注名
            'country' => '地区',
            'purchase_date' => '购买日期',
            'expire_date' => '过期时间',
            // 以下列不由平台管理（客户自行编辑，同步不碰）
            // 'remark' => '备注',
            // 'remark1' => '备注1',
            // 'renew_status' => '续费情况',
        ];
    }

    /**
     * 获取生效的映射（自定义 > 默认）
     */
    public function effectiveMapping(): array
    {
        $custom = $this->field_mapping ?? [];
        return array_merge(self::defaultFieldMapping(), $custom);
    }
}
