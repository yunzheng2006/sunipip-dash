<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparkState extends Model
{
    protected $table = 'area_state';
    public $timestamps = false;

    protected $fillable = ['country_id', 'code', 'name', 'cname', 'code_full'];

    public function country()
    {
        return $this->belongsTo(SparkCountry::class, 'country_id');
    }

    public function cities()
    {
        return $this->hasMany(SparkCity::class, 'state_id');
    }
}
