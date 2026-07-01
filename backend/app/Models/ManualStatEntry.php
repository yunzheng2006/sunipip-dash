<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualStatEntry extends Model
{
    protected $fillable = [
        'customer_id',
        'sales_person',
        'spending',
        'sales_cost',
        'hard_cost',
        'entry_date',
        'note',
        'created_by',
    ];

    protected $casts = [
        'spending' => 'decimal:2',
        'sales_cost' => 'decimal:2',
        'hard_cost' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
