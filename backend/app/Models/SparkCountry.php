<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparkCountry extends Model
{
    protected $table = 'area_country';
    public $timestamps = false;

    protected $fillable = ['code', 'name', 'full_name', 'cname', 'full_cname', 'continent_id'];

    public function states()
    {
        return $this->hasMany(SparkState::class, 'country_id');
    }

    // 通过代码查中文名
    public static function getNameByCode(string $code): ?string
    {
        $country = static::where('code', $code)->first();
        return $country?->cname ?: $country?->name;
    }
}
