<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 用法: ->middleware('perm:customer.view')
 * 超级管理员自动放行
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => '未登录'], 401);
        }

        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => '无权限: ' . implode('|', $permissions),
        ], 403);
    }
}
