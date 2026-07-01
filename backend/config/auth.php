<?php

/**
 * Laravel 11 默认无 config/auth.php，这里显式声明多 provider：
 *   - users: 后台管理员/业务员 (App\Models\User)
 *   - customers: 终端客户，用于 user.sunipip.uk 自助面板 (App\Models\Customer)
 *
 * Sanctum 的 token 是多态的（tokenable_type），两类模型都 use HasApiTokens，
 * 身份隔离完全靠 token abilities（admin / customer），见 AuthController 的 createToken。
 */
return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        'customers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Customer::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
        'customers' => [
            'provider' => 'customers',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
