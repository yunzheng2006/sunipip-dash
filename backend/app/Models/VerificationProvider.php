<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationProvider extends Model
{
    protected $fillable = [
        'name', 'driver', 'credentials', 'is_active', 'description',
    ];

    protected $casts = [
        'credentials' => 'array',
        'is_active'   => 'boolean',
    ];

    protected $hidden = ['credentials'];

    public static function driverOptions(): array
    {
        return [
            'tencent_face' => [
                'label'  => '腾讯云人脸核身',
                'fields' => ['secret_id', 'secret_key', 'rule_id'],
                'description' => '微信小程序活体人脸核身 - 用于个人实名认证',
            ],
            'tencent_ocr' => [
                'label'  => '腾讯云营业执照核验',
                'fields' => ['secret_id', 'secret_key'],
                'description' => '营业执照OCR识别 + 权威比对 - 用于企业认证',
            ],
            'aliyun' => [
                'label'  => '阿里云身份二要素',
                'fields' => ['access_key_id', 'access_key_secret'],
                'description' => '金融级实人认证 - 身份二要素核验（姓名+身份证）[旧版]',
            ],
        ];
    }

    public static function getActive(string $driver = null): ?self
    {
        $query = static::where('is_active', true);
        if ($driver) {
            $query->where('driver', $driver);
        }
        return $query->first();
    }
}
