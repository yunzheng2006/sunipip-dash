<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $settings = [
            // 平台信息
            [
                'group' => 'platform',
                'key' => 'name',
                'value' => 'SuniPIP',
                'type' => 'string',
                'description' => '平台名称',
            ],
            [
                'group' => 'platform',
                'key' => 'company',
                'value' => '',
                'type' => 'string',
                'description' => '公司名称',
            ],
            [
                'group' => 'platform',
                'key' => 'contact_email',
                'value' => '',
                'type' => 'string',
                'description' => '联系邮箱',
            ],

            // 通知设置
            [
                'group' => 'notification',
                'key' => 'expiry_warn_days',
                'value' => '[7,3,1]',
                'type' => 'json',
                'description' => '到期提前提醒天数',
            ],
            [
                'group' => 'notification',
                'key' => 'low_balance_threshold',
                'value' => '50',
                'type' => 'integer',
                'description' => '低余额提醒阈值(RMB)',
            ],
            [
                'group' => 'notification',
                'key' => 'notify_on_new_order',
                'value' => '1',
                'type' => 'boolean',
                'description' => '新订单创建时发送通知',
            ],

            // 订阅默认值
            [
                'group' => 'subscription',
                'key' => 'default_duration',
                'value' => '1',
                'type' => 'integer',
                'description' => '默认订阅时长',
            ],
            [
                'group' => 'subscription',
                'key' => 'default_unit',
                'value' => '3',
                'type' => 'integer',
                'description' => '默认时长单位(1天2周3月4年)',
            ],
            [
                'group' => 'subscription',
                'key' => 'auto_renew_default',
                'value' => '0',
                'type' => 'boolean',
                'description' => '默认是否自动续费',
            ],

            // 客户账户设置
            [
                'group' => 'customer',
                'key' => 'username_prefix',
                'value' => 'snp_',
                'type' => 'string',
                'description' => '客户用户名自动生成前缀',
            ],
            [
                'group' => 'customer',
                'key' => 'default_password_length',
                'value' => '12',
                'type' => 'integer',
                'description' => '自动生成密码长度',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->insert(array_merge($setting, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
}
