<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    // balance 和 status 不在 fillable 中，防止 mass assignment
    // 修改 balance 使用 increment/decrement，修改 status 使用 forceFill
    protected $fillable = [
        'customer_name', 'display_name', 'username', 'password', 'phone', 'email',
        'email_verified_at', 'company_name', 'is_company', 'business_license',
        'company_id', 'address',
        'sales_person', 'invited_by', 'invite_code_used', 'remark',
        'last_login_at', 'last_login_ip', 'auto_renew_default',
        'forward_certified', 'forward_certified_at', 'forward_certified_by',
        'sms_expiry_notify',
        'verified_type', 'verified_at', 'verified_name',
        'verified_id_number', 'verified_enterprise_name', 'verified_credit_code',
        'verified_license_image',
        'pending_biz_token', 'pending_verify_name', 'pending_verify_id', 'pending_verify_at',
        'vip_tier_id', 'total_spent', 'max_single_topup',
        'referral_code', 'referred_by_customer',
        'withdraw_bank_name', 'withdraw_bank_account', 'withdraw_account_holder',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'balance' => 'decimal:2',
            'commission_balance' => 'decimal:2',
            'status' => 'integer',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'auto_renew_default' => 'boolean',
            'sms_expiry_notify' => 'boolean',
            'forward_certified' => 'boolean',
            'forward_certified_at' => 'datetime',
            'verified_at' => 'datetime',
            'pending_verify_at' => 'datetime',
            'total_spent' => 'decimal:2',
            'max_single_topup' => 'decimal:2',
        ];
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        $map = [
            'proxyIps' => 'proxy_ips',
            'activeSubscriptions' => 'active_subscriptions',
            'proxy_ips_count' => 'proxy_ips_count',
            'active_subscriptions_count' => 'active_subscriptions_count',
        ];
        if (array_key_exists('proxyIps', $array)) {
            $array['proxy_ips'] = $array['proxyIps'];
            unset($array['proxyIps']);
        }
        if (array_key_exists('activeSubscriptions', $array)) {
            $array['active_subscriptions'] = $array['activeSubscriptions'];
            unset($array['activeSubscriptions']);
        }
        return $array;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscriptions()
    {
        return $this->hasMany(Subscription::class)->where('status', 'active');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function proxyIps()
    {
        return $this->hasMany(ProxyIp::class, 'assigned_customer_id');
    }

    public function provisionOrders()
    {
        return $this->hasMany(ProvisionOrder::class);
    }

    public function vipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by_customer');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer');
    }

    public function referralCommissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'referrer_id');
    }
}
