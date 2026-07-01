<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 确保当前请求是客户端 token 发起的（防 admin 误操作客户路由）。
 *
 * 与 `ability:customer` 中间件配合使用：
 *   - ability:customer 保证 token 是 customer ability 签发的
 *   - EnsureCustomer 进一步确认 tokenable 确实是 Customer 实例，且账号启用
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !($user instanceof Customer)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ((int) $user->status !== 1) {
            return response()->json(['message' => '账号已被禁用，请联系管理员'], 403);
        }

        return $next($request);
    }
}
