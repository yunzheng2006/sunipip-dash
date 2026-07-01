<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerIpGroup extends Model
{
    protected $fillable = ['customer_id', 'name', 'sort_order'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function proxyIps(): BelongsToMany
    {
        return $this->belongsToMany(ProxyIp::class, 'customer_ip_group_items', 'group_id', 'proxy_ip_id');
    }
}
