<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingRuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 示例定价规则 - 根据实际业务调整
        $rules = [
            // 泰国 静态住宅 原生
            [
                'country_code' => 'TH',
                'country_name' => '泰国',
                'ip_type' => 'residential',
                'nature' => 'static',
                'net_type' => 'native',
                'duration' => 1,
                'unit' => 3, // 月
                'price' => 19.00,
                'cost_price' => null,
                'is_active' => 1,
            ],
            // 巴西 静态住宅
            [
                'country_code' => 'BR',
                'country_name' => '巴西',
                'ip_type' => 'residential',
                'nature' => 'static',
                'net_type' => null,
                'duration' => 1,
                'unit' => 3,
                'price' => 25.00,
                'cost_price' => null,
                'is_active' => 1,
            ],
            // 墨西哥 静态住宅
            [
                'country_code' => 'MX',
                'country_name' => '墨西哥',
                'ip_type' => 'residential',
                'nature' => 'static',
                'net_type' => null,
                'duration' => 1,
                'unit' => 3,
                'price' => 25.00,
                'cost_price' => null,
                'is_active' => 1,
            ],
            // 美国 静态住宅
            [
                'country_code' => 'US',
                'country_name' => '美国',
                'ip_type' => 'residential',
                'nature' => 'static',
                'net_type' => null,
                'duration' => 1,
                'unit' => 3,
                'price' => 30.00,
                'cost_price' => null,
                'is_active' => 1,
            ],
            // 印尼 静态住宅
            [
                'country_code' => 'ID',
                'country_name' => '印尼',
                'ip_type' => 'residential',
                'nature' => 'static',
                'net_type' => null,
                'duration' => 1,
                'unit' => 3,
                'price' => 20.00,
                'cost_price' => null,
                'is_active' => 1,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('pricing_rules')->insert(array_merge($rule, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
}
