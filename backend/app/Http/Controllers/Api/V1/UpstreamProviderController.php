<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UpstreamProvider;
use App\Services\IpipvApiService;
use App\Services\SparkApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpstreamProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = UpstreamProvider::orderBy('id')->get();

        $data = $providers->map(function (UpstreamProvider $p) {
            $creds = $p->credentials ?? [];
            $maskedCreds = [];
            foreach ($creds as $key => $val) {
                if (!$val) {
                    $maskedCreds[$key] = '';
                } elseif (in_array($key, ['aes_key', 'app_secret'])) {
                    $maskedCreds[$key] = substr($val, 0, 4) . str_repeat('*', max(0, strlen($val) - 8)) . substr($val, -4);
                } else {
                    $maskedCreds[$key] = $val;
                }
            }
            return [
                'id'           => $p->id,
                'name'         => $p->name,
                'remark'       => $p->remark,
                'slug'         => $p->slug,
                'driver'       => $p->driver,
                'api_url'      => $p->api_url,
                'credentials'  => $maskedCreds,
                'callback_url' => $p->callback_url,
                'is_active'    => $p->is_active,
                'public_sale'  => (bool) $p->public_sale,
                'extra_config' => $p->extra_config,
                'created_at'   => $p->created_at,
                'updated_at'   => $p->updated_at,
            ];
        });

        return response()->json([
            'success'        => true,
            'data'           => [
                'providers'      => $data,
                'driver_options' => UpstreamProvider::driverOptions(),
            ],
        ]);
    }

    public function displayNames(): JsonResponse
    {
        $providers = UpstreamProvider::where('is_active', true)
            ->get(['driver', 'name', 'remark']);

        $names = [];
        foreach ($providers as $p) {
            $names[$p->driver] = $p->remark ?: $p->name;
        }

        return response()->json(['success' => true, 'data' => $names]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'remark'      => 'nullable|string|max:200',
            'slug'        => 'required|string|max:50|unique:upstream_providers,slug|regex:/^[a-z0-9_-]+$/',
            'driver'      => 'required|string|in:spark,ipipv',
            'api_url'     => 'required|url|max:500',
            'credentials' => 'required|array',
            'is_active'   => 'boolean',
        ]);

        $callbackPath = match ($request->input('driver')) {
            'spark' => '/api/v1/spark/notify',
            default => "/api/v1/upstream/{$request->input('slug')}/callback",
        };

        $provider = UpstreamProvider::create([
            'name'          => $request->input('name'),
            'remark'        => $request->input('remark'),
            'slug'          => $request->input('slug'),
            'driver'        => $request->input('driver'),
            'api_url'       => $request->input('api_url'),
            'credentials'   => $request->input('credentials'),
            'callback_path' => $callbackPath,
            'is_active'     => $request->boolean('is_active', true),
            'public_sale'   => $request->boolean('public_sale', false),
            'extra_config'  => $request->input('extra_config'),
        ]);

        return response()->json(['success' => true, 'data' => $provider], 201);
    }

    public function update(Request $request, UpstreamProvider $upstreamProvider): JsonResponse
    {
        $request->validate([
            'name'        => 'string|max:100',
            'remark'      => 'nullable|string|max:200',
            'api_url'     => 'url|max:500',
            'credentials' => 'array',
            'is_active'   => 'boolean',
            'public_sale' => 'boolean',
        ]);

        $data = $request->only(['name', 'remark', 'api_url', 'is_active', 'public_sale', 'extra_config']);

        if ($request->has('credentials')) {
            $newCreds = $request->input('credentials');
            $oldCreds = $upstreamProvider->credentials ?? [];
            // 保留被掩码的字段：如果新值包含 * 则保留旧值
            foreach ($newCreds as $key => $val) {
                if (str_contains($val ?? '', '*')) {
                    $newCreds[$key] = $oldCreds[$key] ?? '';
                }
            }
            $data['credentials'] = $newCreds;
        }

        $upstreamProvider->update($data);

        return response()->json(['success' => true, 'data' => $upstreamProvider->fresh()]);
    }

    public function destroy(UpstreamProvider $upstreamProvider): JsonResponse
    {
        $upstreamProvider->delete();
        return response()->json(['success' => true]);
    }

    public function test(UpstreamProvider $upstreamProvider): JsonResponse
    {
        try {
            $result = match ($upstreamProvider->driver) {
                'spark' => $this->testSpark($upstreamProvider),
                'ipipv' => $this->testIpipv($upstreamProvider),
                default => throw new \RuntimeException("不支持的驱动类型: {$upstreamProvider->driver}"),
            };

            return response()->json([
                'success' => true,
                'message' => '连接成功',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage(),
            ], 422);
        }
    }

    private function testSpark(UpstreamProvider $provider): array
    {
        $api = new SparkApiService();
        $balance = $api->getBalance();
        return ['balance' => $balance];
    }

    private function testIpipv(UpstreamProvider $provider): array
    {
        $api = new IpipvApiService($provider);
        $info = $api->getAppInfo();
        return [
            'app_name' => $info['appName'] ?? null,
            'balance'  => $info['coin'] ?? null,
            'credit'   => $info['credit'] ?? null,
            'status'   => $info['status'] ?? null,
        ];
    }
}
