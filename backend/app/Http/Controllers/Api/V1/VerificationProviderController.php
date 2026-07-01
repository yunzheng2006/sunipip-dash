<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use App\Models\VerificationProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = VerificationProvider::orderByDesc('is_active')->orderByDesc('updated_at')->get();

        $providers->each(function ($p) {
            $p->makeVisible('credentials');
            if ($p->credentials) {
                $masked = [];
                foreach ($p->credentials as $k => $v) {
                    $masked[$k] = $v ? $this->maskValue($v) : '';
                }
                $p->credentials_masked = $masked;
            }
            $p->makeHidden('credentials');
        });

        return $this->success([
            'providers' => $providers,
            'driver_options' => VerificationProvider::driverOptions(),
            'global_settings' => [
                'verification.required' => (bool) SystemConfig::get('verification.required', false),
                'verification.allow_personal' => (bool) SystemConfig::get('verification.allow_personal', true),
                'verification.allow_enterprise' => (bool) SystemConfig::get('verification.allow_enterprise', true),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'driver' => 'required|in:aliyun,tencent,tencent_face,tencent_ocr',
            'credentials' => 'required|array',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:500',
        ]);

        $provider = VerificationProvider::create($request->only(['name', 'driver', 'credentials', 'is_active', 'description']));

        return $this->success($provider, '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $provider = VerificationProvider::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:100',
            'credentials' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        $data = $request->only(['name', 'credentials', 'is_active', 'description']);

        if (isset($data['credentials'])) {
            $existing = $provider->makeVisible('credentials')->credentials ?? [];
            foreach ($data['credentials'] as $k => $v) {
                if ($v === '' || $v === null) {
                    $data['credentials'][$k] = $existing[$k] ?? '';
                }
            }
            $provider->makeHidden('credentials');
        }

        $provider->update($data);

        return $this->success($provider, '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        $provider = VerificationProvider::findOrFail($id);
        $provider->delete();

        return $this->success(null, '删除成功');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $provider = VerificationProvider::findOrFail($id);
        $provider->update(['is_active' => !$provider->is_active]);

        return $this->success(['is_active' => $provider->is_active]);
    }

    public function test(int $id): JsonResponse
    {
        $provider = VerificationProvider::findOrFail($id);
        $provider->makeVisible('credentials');

        try {
            $service = app(\App\Services\VerificationService::class);
            $result = $service->testProvider($provider);

            return $this->success([
                'success' => $result['success'] ?? $result['match'] ?? false,
                'message' => $result['message'] ?? '连接正常',
            ], $result['message'] ?? '测试完成');
        } catch (\Throwable $e) {
            return $this->error('测试失败: ' . $e->getMessage(), 422);
        }
    }

    public function updateGlobalSettings(Request $request): JsonResponse
    {
        $boolKeys = ['verification.required', 'verification.allow_personal', 'verification.allow_enterprise'];

        foreach ($boolKeys as $key) {
            if ($request->has($key)) {
                SystemConfig::set($key, $request->input($key) ? '1' : '0', 'boolean', 'verification');
            }
        }

        return $this->success(null, '设置已保存');
    }

    private function maskValue(string $value): string
    {
        $len = mb_strlen($value);
        if ($len <= 6) return str_repeat('*', $len);
        return substr($value, 0, 3) . str_repeat('*', min($len - 6, 10)) . substr($value, -3);
    }
}
