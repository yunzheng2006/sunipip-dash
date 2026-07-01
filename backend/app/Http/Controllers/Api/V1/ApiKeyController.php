<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        $keys = ApiKey::orderByDesc('id')->get()
            ->map(fn($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'key' => $k->key,
                'scopes' => $k->scopes,
                'price_markup' => (float) $k->price_markup,
                'rate_limit' => $k->rate_limit,
                'request_count' => $k->request_count,
                'last_used_at' => $k->last_used_at,
                'last_used_ip' => $k->last_used_ip,
                'is_active' => (bool) $k->is_active,
                'expires_at' => $k->expires_at,
                'remark' => $k->remark,
                'created_at' => $k->created_at,
            ]);
        return $this->success($keys);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|in:store.products,store.stock,vip.tiers',
            'price_markup' => 'nullable|numeric|min:0.1|max:10',
            'rate_limit' => 'nullable|integer|min:10|max:10000',
            'expires_at' => 'nullable|date|after:now',
            'remark' => 'nullable|string|max:500',
        ]);

        $apiKey = ApiKey::create([
            'name' => $data['name'],
            'key' => ApiKey::generateKey(),
            'secret' => ApiKey::generateSecret(),
            'scopes' => $data['scopes'] ?? null,
            'price_markup' => $data['price_markup'] ?? 1.00,
            'rate_limit' => $data['rate_limit'] ?? 60,
            'is_active' => true,
            'expires_at' => $data['expires_at'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);

        // 仅此时返回 secret
        return $this->success([
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'key' => $apiKey->key,
            'secret' => $apiKey->secret,
            'warning' => 'Secret 只在创建时返回一次，请立即保存！',
        ], 'API Key 已创建');
    }

    public function update(Request $request, ApiKey $apiKey): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|in:store.products,store.stock,vip.tiers',
            'price_markup' => 'nullable|numeric|min:0.1|max:10',
            'rate_limit' => 'nullable|integer|min:10|max:10000',
            'is_active' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
        ]);
        $apiKey->update($data);
        return $this->success($apiKey->fresh(), '已更新');
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->delete();
        return $this->success(null, '已删除');
    }

    public function regenerateSecret(ApiKey $apiKey): JsonResponse
    {
        $newSecret = ApiKey::generateSecret();
        $apiKey->update(['secret' => $newSecret]);
        return $this->success([
            'secret' => $newSecret,
            'warning' => '新 Secret 只返回一次，请立即保存！',
        ], 'Secret 已重置');
    }
}
