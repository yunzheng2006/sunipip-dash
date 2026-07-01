<?php

use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\HandleCors;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 信任 Cloudflare 代理，使 $request->ip() 返回真实客户端 IP
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        // CORS 中间件 — 全局生效（在所有其他中间件之前处理 preflight）
        $middleware->prepend(HandleCors::class);

        // 注册 API 中间件别名
        $middleware->alias([
            'log.activity' => LogActivity::class,
            'perm' => RequirePermission::class,
            'customer.auth' => EnsureCustomer::class,
            'customer.verified' => \App\Http\Middleware\EnsureVerified::class,
            'api.key' => \App\Http\Middleware\VerifyApiKey::class,
            'oauth.bearer' => \App\Http\Middleware\VerifyOauthBearerToken::class,
            // Laravel 11 不再自动注册 Sanctum ability 别名，需手动声明
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 所有 /api/* 请求未认证时返回 401 JSON，而不是重定向到 login 路由
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
