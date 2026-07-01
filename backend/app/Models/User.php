<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $guard_name = 'api';

    protected $fillable = [
        'username',
        'password',
        'name',
        'phone',
        'email',
        'status',
        'invite_code',
        'supervisor_id',
        'auto_approve',
        'auto_approve_forward',
        'commission_balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'integer',
            'auto_approve' => 'boolean',
            'auto_approve_forward' => 'boolean',
            'commission_balance' => 'decimal:2',
        ];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }
}
