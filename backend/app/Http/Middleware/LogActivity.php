<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;

class LogActivity
{
    /**
     * 自动记录所有写操作（POST/PUT/PATCH/DELETE）的日志
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // 只记录已登录用户的写操作，且响应成功
        if (
            in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])
            && $request->user()
            && $response->getStatusCode() < 400
        ) {
            $this->log($request, $response);
        }

        return $response;
    }

    private function log(Request $request, $response): void
    {
        try {
            $path = $request->path();
            $method = $request->method();
            $action = $this->resolveAction($method, $path);

            // 从路径解析 subject_type + subject_id
            [$subjectType, $subjectId] = $this->resolveSubject($path, $request);

            // 过滤掉敏感字段
            $requestData = $request->except(['password', 'old_password', 'new_password', 'token', '_token']);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'description' => $this->buildDescription($method, $path, $request),
                'properties' => [
                    'method' => $method,
                    'path' => $path,
                    'request' => $requestData,
                    'user_agent' => $request->userAgent(),
                ],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // 日志失败不应影响主流程
            \Log::warning("Activity log failed: {$e->getMessage()}");
        }
    }

    private function resolveAction(string $method, string $path): string
    {
        // login 特殊处理
        if (str_contains($path, 'auth/login')) return 'login';
        if (str_contains($path, 'auth/logout')) return 'logout';
        if (str_contains($path, 'topup')) return 'topup';
        if (str_contains($path, 'assign')) return 'assign';
        if (str_contains($path, 'renew')) return 'renew';
        if (str_contains($path, 'cancel')) return 'cancel';
        if (str_contains($path, 'import')) return 'import';

        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'unknown',
        };
    }

    private function resolveSubject(string $path, Request $request): array
    {
        // 从路径中提取模块名：api/v1/customers/123 → customers
        preg_match('#api/v1/([a-z-]+)(/(\d+))?#', $path, $m);
        $module = $m[1] ?? null;
        $id = isset($m[3]) ? (int) $m[3] : null;

        $typeMap = [
            'customers' => 'Customer',
            'proxy-ips' => 'ProxyIp',
            'subscriptions' => 'Subscription',
            'users' => 'User',
            'asset-groups' => 'IpAssetGroup',
            'ip-groups' => 'IpGroup',
            'pricing-rules' => 'PricingRule',
            'transactions' => 'Transaction',
        ];

        return [$typeMap[$module] ?? $module, $id];
    }

    private function buildDescription(string $method, string $path, Request $request): string
    {
        $action = $this->resolveAction($method, $path);
        $actionLabel = [
            'create' => '创建', 'update' => '更新', 'delete' => '删除',
            'login' => '登录', 'logout' => '退出', 'topup' => '充值',
            'assign' => '分配IP', 'renew' => '续费', 'cancel' => '取消',
            'import' => '批量导入',
        ][$action] ?? $action;

        [$type] = $this->resolveSubject($path, $request);
        $typeLabel = [
            'Customer' => '客户', 'ProxyIp' => 'IP资产', 'Subscription' => '订阅',
            'User' => '用户', 'IpAssetGroup' => '资产组', 'IpGroup' => 'IP组',
            'PricingRule' => '定价规则',
        ][$type] ?? $type;

        return "{$actionLabel} {$typeLabel}";
    }
}
