<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->success([
            'verification.required' => SystemConfig::get('verification.required', false),
            'verification.allow_personal' => SystemConfig::get('verification.allow_personal', true),
            'verification.allow_enterprise' => SystemConfig::get('verification.allow_enterprise', true),
            'verification.aliyun_access_key_id' => SystemConfig::get('verification.aliyun_access_key_id') ? '******' : null,
            'verification.has_secret' => !empty(SystemConfig::get('verification.aliyun_access_key_secret')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        $boolKeys = ['verification.required', 'verification.allow_personal', 'verification.allow_enterprise'];
        $strKeys = ['verification.aliyun_access_key_id', 'verification.aliyun_access_key_secret'];

        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $body)) {
                SystemConfig::set($key, $body[$key] ? '1' : '0', 'boolean', 'verification');
            }
        }
        foreach ($strKeys as $key) {
            if (array_key_exists($key, $body) && is_string($body[$key]) && $body[$key] !== '') {
                SystemConfig::set($key, $body[$key], 'string', 'verification');
            }
        }

        return $this->success(null, '设置已保存');
    }

    public function test(): JsonResponse
    {
        try {
            $result = app(\App\Services\VerificationService::class)->testConnection();

            return $this->success([
                'match' => $result['match'],
                'request_id' => $result['request_id'] ?? null,
            ], 'API 连通正常：' . $result['message']);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'InvalidAccessKeyId')) return $this->error('AccessKey ID 无效', 422);
            if (str_contains($msg, 'SignatureDoesNotMatch')) return $this->error('AccessKey Secret 错误', 422);
            if (str_contains($msg, 'Forbidden') || str_contains($msg, '无权限') || str_contains($msg, 'NoPermission'))
                return $this->error('权限不足：请在阿里云控制台开通「金融级实人认证」服务', 422);
            if (str_contains($msg, 'timeout')) return $this->error('请求超时，服务器无法连接阿里云', 422);
            return $this->error('测试失败: ' . $msg, 422);
        }
    }
}
