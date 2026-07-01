<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparkCity extends Model
{
    protected $table = 'area_city';
    public $timestamps = false;

    protected $fillable = ['state_id', 'country_id', 'country_code', 'code', 'name', 'cname', 'code_full'];

    public function country()
    {
        return $this->belongsTo(SparkCountry::class, 'country_id');
    }

    public function state()
    {
        return $this->belongsTo(SparkState::class, 'state_id');
    }
}
