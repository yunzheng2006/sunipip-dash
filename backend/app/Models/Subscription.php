<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'customer_id', 'proxy_ip_id', 'provision_order_id', 'price',
        'admin_set_price',
        'list_price', 'sales_cost', 'hard_cost', 'duration', 'unit',
        'initial_duration', 'initial_unit',
        'started_at', 'expires_at', 'auto_renew',
        'is_test', 'test_reclaim_at',
        'has_forward', 'purchased_module', 'status', 'keep_performance', 'renewed_count', 'last_renewed_at',
        'remark', 'customer_remark', 'created_by', 'balance_deducted',
    ];

    public function toArray(): array
    {
        $array = parent::toArray();
        $map = [
            'proxyIp' => 'proxy_ip',
            'provisionOrder' => 'provision_order',
            'forwardRule' => 'forward_rule',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $array)) {
                $array[$to] = $array[$from];
                unset($array[$from]);
            }
        }
        return $array;
    }

    public function forwardRule()
    {
        return $this->hasOne(ForwardRule::class)->where('status', '!=', 'deleted');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'admin_set_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'sales_cost' => 'decimal:2',
            'hard_cost' => 'decimal:2',
            'duration' => 'integer',
            'unit' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'auto_renew' => 'integer',
            'is_test' => 'boolean',
            'test_reclaim_at' => 'datetime',
            'balance_deducted' => 'boolean',
            'has_forward' => 'boolean',
            'renewed_count' => 'integer',
            'last_renewed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function proxyIp(): BelongsTo
    {
        return $this->belongsTo(ProxyIp::class)->withTrashed();
    }

    public function provisionOrder(): BelongsTo
    {
        return $this->belongsTo(ProvisionOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function routerWifiAccounts()
    {
        return $this->hasMany(RouterWifiAccount::class, 'proxy_subscription_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription) {
            if ($subscription->initial_duration === null && $subscription->duration !== null) {
                $subscription->initial_duration = $subscription->duration;
                $subscription->initial_unit = $subscription->unit;
            }
        });

        static::updated(function (Subscription $subscription) {
            if (!$subscription->wasChanged('status')) return;
            if (!in_array($subscription->status, ['expired', 'cancelled'])) return;

            $deviceIds = $subscription->routerWifiAccounts()
                ->distinct()
                ->pluck('router_device_id');

            if ($deviceIds->isEmpty()) return;

            $configService = app(\App\Services\Router\RouterConfigService::class);
            foreach (RouterDevice::whereIn('id', $deviceIds)->get() as $device) {
                $configService->pushConfig($device);
            }
        });
    }

    public function isExpiringSoon(int $days = 7): bool
    {
        return $this->status === 'active' && $this->expires_at->diffInDays(now()) <= $days;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '<=', now()->addDays($days))
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '<=', now());
    }

    public function getUnitLabel(): string
    {
        return match ($this->unit) {
            1 => '天', 2 => '周', 3 => '月', 4 => '年', default => '',
        };
    }
}
