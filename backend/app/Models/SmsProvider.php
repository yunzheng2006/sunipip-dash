<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsProvider extends Model
{
    protected $fillable = ['name', 'type', 'config', 'is_active', 'sort', 'description', 'expiry_template_code'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'integer',
        ];
    }
}
