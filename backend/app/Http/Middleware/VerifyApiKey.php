<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class VerifyApiKey
{
    /**
     * 验证 API Key（在 Header: X-API-Key，或 query ?api_key=）
     * 可选签名验证（Header: X-API-Signature = HMAC-SHA256(secret, timestamp + path)）
     */
    public function handle(Request $request, Closure $next, string $scope = '')
    {
        $key = $request->header('X-API-Key') ?: $request->query('api_key');

        if (!$key) {
            return response()->json(['message' => '缺少 API Key'], 401);
        }

        $apiKey = ApiKey::where('key', $key)->first();

        if (!$apiKey) {
            return response()->json(['message' => 'API Key 无效'], 401);
        }

        if (!$apiKey->is_active) {
            return response()->json(['message' => 'API Key 已禁用'], 403);
        }

        if ($apiKey->isExpired()) {
            return response()->json(['message' => 'API Key 已过期'], 403);
        }

        if ($scope && !$apiKey->hasScope($scope)) {
            return response()->json(['message' => "无 {$scope} 权限"], 403);
        }

        // 限流
        $limitKey = 'api_key_rate:' . $apiKey->id;
        if (RateLimiter::tooManyAttempts($limitKey, $apiKey->rate_limit)) {
            $seconds = RateLimiter::availableIn($limitKey);
            return response()->json([
                'message' => "请求过于频繁，请 {$seconds} 秒后再试",
            ], 429);
        }
        RateLimiter::hit($limitKey, 60);

        // 可选签名校验（如果配置了）
        $signature = $request->header('X-API-Signature');
        $timestamp = $request->header('X-API-Timestamp');
        if ($signature) {
            if (!$timestamp || abs(time() - (int) $timestamp) > 300) {
                return response()->json(['message' => '请求时间戳无效或超过 5 分钟'], 401);
            }
            $expected = hash_hmac('sha256', $timestamp . $request->path(), $apiKey->secret);
            if (!hash_equals($expected, $signature)) {
                return response()->json(['message' => '签名不匹配'], 401);
            }
        }

        // 记录使用
        $apiKey->increment('request_count');
        $apiKey->update([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ]);

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
