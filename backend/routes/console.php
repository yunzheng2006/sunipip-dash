<?php

use Illuminate\Support\Facades\Schedule;

// 每天 09:00 检查订阅到期 / 客户余额，触发 webhook 通知
Schedule::command('subscriptions:check-expiring')
    ->dailyAt('09:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping()
    ->runInBackground();

// 每 5 分钟刷新 Spark 产品库存缓存（客户自助面板用）
Schedule::command('spark:refresh-stock')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// 每 5 分钟刷新 IPIPV 产品库存缓存
Schedule::command('ipipv:refresh-stock')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// 飞书同步：事件驱动（不轮询），操作触发时调 feishu:sync --id=X
// 保留 artisan 命令但不注册定时任务

// 每小时自动标记过期订阅 + 处理 Spark 3天宽限期
Schedule::command('subscriptions:expire')
    ->hourly()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping()
    ->runInBackground();

// 每分钟扫描 Spark 待处理订单（1分钟 cutoff），确保开通后快速同步
Schedule::command('spark:sync-pending --minutes=1')
    ->everyMinute()
    ->withoutOverlapping(3)
    ->runInBackground();

// 每分钟扫描 IPIPV 待处理订单
Schedule::command('ipipv:sync-pending --minutes=1')
    ->everyMinute()
    ->withoutOverlapping(3)
    ->runInBackground();

// 每天 10:00 向开启短信提醒的客户发送IP到期前1天提醒
Schedule::command('sms:send-expiry')
    ->dailyAt('10:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping()
    ->runInBackground();

// 每天 00:00 处理开启自动续费的订阅（首次尝试；失败后次日 00:00 最终尝试）
Schedule::command('subscriptions:auto-renew')
    ->dailyAt('00:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping()
    ->runInBackground();

// 每天 06:00 自动续费即将到期的上游实例（Spark+IPIPV 按月滚动，与客户计费解耦）
Schedule::command('upstream:auto-renew')
    ->dailyAt('06:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping()
    ->runInBackground();

// 每 10 分钟自动重试失败的转发规则（超时、限流等临时性故障）
Schedule::command('forward:retry-failed --force')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// 每分钟检测软路由设备在线状态，超过5分钟无心跳标记为 offline
Schedule::command('router:check-online')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
