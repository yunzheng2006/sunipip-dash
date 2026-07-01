<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProxyIp extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'asset_group_id', 'ip_group_id', 'socks5_info', 'ip_address', 'port',
        'auth_username', 'auth_password', 'protocol', 'asset_name',
        'country_code', 'country_name', 'city', 'ip_type', 'nature',
        'net_type', 'source_name', 'sales_cost', 'status', 'access_type',
        'shared_user_count', 'max_shared_users', 'assigned_customer_id',
        'qr_code_data', 'spark_instance_id', 'ipipv_instance_id', 'extra_config',
        'import_batch_id', 'remark', 'upstream_expires_at',
        'released_at', 'release_reason', 'released_by',
        'spark_release_status', 'spark_release_order_no',
        'spark_released_at', 'spark_release_error',
        'is_test_pool', 'test_pool_added_at', 'test_pool_added_by', 'test_pool_reason',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'sales_cost' => 'decimal:2',
            'extra_config' => 'array',
            'upstream_expires_at' => 'datetime',
            'released_at' => 'datetime',
            'spark_released_at' => 'datetime',
            'is_test_pool' => 'boolean',
            'test_pool_added_at' => 'datetime',
        ];
    }

    /**
     * 将关联键转为 snake_case 以便前端统一消费
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $renameMap = [
            'assetGroup' => 'asset_group',
            'ipGroup' => 'ip_group',
            'assignedCustomer' => 'assigned_customer',
            'activeSubscription' => 'active_subscription',
            'assignmentLogs' => 'assignment_logs',
            'sparkInstance' => 'spark_instance',
            'importLog' => 'import_log',
        ];
        foreach ($renameMap as $from => $to) {
            if (array_key_exists($from, $array)) {
                $array[$to] = $array[$from];
                unset($array[$from]);
            }
        }
        return $array;
    }

    public function assetGroup(): BelongsTo
    {
        return $this->belongsTo(IpAssetGroup::class, 'asset_group_id');
    }

    public function ipGroup(): BelongsTo
    {
        return $this->belongsTo(IpGroup::class);
    }

    public function assignedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'assigned_customer_id');
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(IpImportLog::class, 'import_batch_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('status', 'active');
    }

    public function assignmentLogs(): HasMany
    {
        return $this->hasMany(IpAssignmentLog::class);
    }

    public function sparkInstance()
    {
        return $this->hasOne(SparkInstance::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    // 从 socks5_info 自动解析字段
    public static function parseSocks5Info(string $socks5Info): array
    {
        $parts = explode(':', $socks5Info);
        return [
            'ip_address' => $parts[0] ?? '',
            'port' => (int) ($parts[1] ?? 0),
            'auth_username' => $parts[2] ?? '',
            'auth_password' => $parts[3] ?? '',
        ];
    }
}
