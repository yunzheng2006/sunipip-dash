<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralCommission extends Model
{
    protected $fillable = [
        'referrer_id', 'referee_id', 'trigger_type', 'trigger_id',
        'trigger_amount', 'commission_rate', 'commission_amount',
        'status', 'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'credited_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo { return $this->belongsTo(Customer::class, 'referrer_id'); }
    public function referee(): BelongsTo { return $this->belongsTo(Customer::class, 'referee_id'); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class, 'trigger_id'); }
}
