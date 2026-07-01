<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = SmsProvider::orderBy('sort')->orderBy('id')->get()
            ->map(function ($p) {
                $arr = $p->toArray();
                // Show partial config for display (hide secrets)
                $config = $p->config ?? [];
                $arr['config_display'] = [
                    'sign_name' => $config['sign_name'] ?? null,
                    'template_code' => $config['template_code'] ?? null,
                    'access_key_id' => isset($config['access_key_id']) ? substr($config['access_key_id'], 0, 6) . '****' : null,
                ];
                return $arr;
            });
        return $this->success($providers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:aliyun,tencent',
            'config' => 'required|array',
            'config.access_key_id' => 'required|string',
            'config.access_key_secret' => 'required|string',
            'config.sign_name' => 'required|string',
            'config.template_code' => 'required|string',
            'expiry_template_code' => 'nullable|string|max:100',
            'is_active' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
        ]);
        $provider = SmsProvider::create($data);
        return $this->success($provider, '创建成功');
    }

    public function update(Request $request, SmsProvider $smsProvider): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'config' => 'nullable|array',
            'expiry_template_code' => 'nullable|string|max:100',
            'is_active' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
        ]);
        // Preserve secrets if not provided
        if (isset($data['config'])) {
            $existing = $smsProvider->config ?? [];
            if (empty($data['config']['access_key_id'])) {
                $data['config']['access_key_id'] = $existing['access_key_id'] ?? '';
            }
            if (empty($data['config']['access_key_secret'])) {
                $data['config']['access_key_secret'] = $existing['access_key_secret'] ?? '';
            }
        }
        $smsProvider->update($data);
        return $this->success($smsProvider->fresh(), '更新成功');
    }

    public function destroy(SmsProvider $smsProvider): JsonResponse
    {
        $smsProvider->delete();
        return $this->success(null, '已删除');
    }

    public function test(Request $request, SmsProvider $smsProvider): JsonResponse
    {
        $phone = $request->validate(['phone' => 'required|string|max:20'])['phone'];
        $sms = app(\App\Services\SmsService::class);
        $result = $sms->sendCode($phone, 'test', $request->ip());
        return $result['ok'] ? $this->success(null, $result['message']) : $this->error($result['message'], 422);
    }

    public function testExpiry(Request $request, SmsProvider $smsProvider): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'count' => 'nullable|integer|min:1|max:999',
        ]);
        $sms = app(\App\Services\SmsService::class);
        $result = $sms->sendExpirySms($data['phone'], $data['count'] ?? 3);
        return $result['ok'] ? $this->success(null, $result['message']) : $this->error($result['message'], 422);
    }
}
