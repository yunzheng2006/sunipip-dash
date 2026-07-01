<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Subscription;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SendExpirySms extends Command
{
    protected $signature = 'sms:send-expiry {--dry : 试运行，不实际发送}';

    protected $description = '向开启短信提醒的客户发送IP到期提醒（到期前1天）';

    public function handle(SmsService $sms): int
    {
        $dry = (bool) $this->option('dry');

        $tomorrow = now()->addDay()->startOfDay();
        $tomorrowEnd = now()->addDay()->endOfDay();

        $subs = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [$tomorrow, $tomorrowEnd])
            ->select('customer_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as cnt'))
            ->groupBy('customer_id')
            ->get();

        if ($subs->isEmpty()) {
            $this->info('无明日到期的订阅');
            return self::SUCCESS;
        }

        $customerIds = $subs->pluck('customer_id')->all();
        $countMap = $subs->pluck('cnt', 'customer_id');

        $customers = Customer::whereIn('id', $customerIds)
            ->where('status', 1)
            ->where('sms_expiry_notify', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        $this->info("明日到期客户: {$subs->count()}, 开启短信提醒: {$customers->count()}");

        $sent = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            $count = $countMap[$customer->id] ?? 0;
            if ($count <= 0) continue;

            if ($dry) {
                $this->line("  [DRY] {$customer->customer_name} ({$customer->phone}) → {$count}条到期");
                continue;
            }

            $result = $sms->sendExpirySms($customer->phone, $count);
            if ($result['ok']) {
                $sent++;
                $this->line("  ✓ {$customer->customer_name} ({$customer->phone}) → {$count}条");
            } else {
                $failed++;
                $this->warn("  ✗ {$customer->customer_name}: {$result['message']}");
            }

            usleep(200_000);
        }

        $this->info("✓ 完成" . ($dry ? '（试运行）' : '') . " 发送:{$sent} 失败:{$failed}");
        return self::SUCCESS;
    }
}
