<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Date;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 强制设置时区为 UTC+8
        date_default_timezone_set('Asia/Shanghai');
        config(['app.timezone' => 'Asia/Shanghai']);
    }

    public function boot(): void
    {
        //
    }
}
