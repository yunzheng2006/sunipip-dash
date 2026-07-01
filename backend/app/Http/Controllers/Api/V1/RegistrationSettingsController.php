<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationSettingsController extends Controller
{
    private array $keys = [
        'registration.enabled' => ['type' => 'boolean', 'default' => true, 'desc' => '是否开放注册'],
        'registration.require_phone' => ['type' => 'boolean', 'default' => false, 'desc' => '注册强制验证手机号'],
        'registration.require_invite' => ['type' => 'boolean', 'default' => false, 'desc' => '注册必须填写邀请码'],
        'registration.require_company' => ['type' => 'boolean', 'default' => false, 'desc' => '注册必须为企业账号'],
        'registration.default_balance' => ['type' => 'integer', 'default' => 0, 'desc' => '新用户默认余额'],
        'registration.invite_auto_forward' => ['type' => 'boolean', 'default' => true, 'desc' => '邀请注册自动开通中转权限'],
        'customer.self_refund_enabled' => ['type' => 'boolean', 'default' => false, 'desc' => '客户自助退款开关'],
    ];

    public function show(): JsonResponse
    {
        $settings = [];
        foreach ($this->keys as $key => $meta) {
            $settings[$key] = SystemConfig::get($key, $meta['default']);
        }
        return $this->success($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration.enabled' => 'nullable|boolean',
            'registration.require_phone' => 'nullable|boolean',
            'registration.require_invite' => 'nullable|boolean',
            'registration.require_company' => 'nullable|boolean',
            'registration.default_balance' => 'nullable|integer|min:0',
            'registration.invite_auto_forward' => 'nullable|boolean',
            'customer.self_refund_enabled' => 'nullable|boolean',
        ]);

        foreach ($data as $key => $value) {
            if (isset($this->keys[$key])) {
                SystemConfig::set($key, $value, $this->keys[$key]['type'], 'registration', $this->keys[$key]['desc']);
            }
        }

        return $this->success(null, '设置已保存');
    }
}
