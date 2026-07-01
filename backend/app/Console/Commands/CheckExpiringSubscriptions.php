<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\WebhookConfig;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * 扫描订阅到期情况，按各 webhook 配置的"提前天数"派发提醒。
 *
 * 建议在 Laravel Scheduler / Cron 每天 09:00 运行一次：
 *   php artisan subscriptions:check-expiring
 *
 * 逻辑：
 *   1. 收集所有活跃 webhook 订阅了 subscription_expiring 的配置
 *   2. 合并得到所有要检查的"提前天数集合"
 *   3. 对每个天数 N：查出 N 天后到期的订阅（0点~23:59 范围），触发事件
 *   4. 同时派发 subscription_expired 事件（今日已过期的）
 *   5. 检查 customer_low_balance（余额 < 阈值）
 */
class CheckExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:check-expiring {--dry : 试运行，不实际发送}';

    protected $description = '扫描订阅到期 / 客户余额状态，向各 webhook 派发通知';

    private function dispatchPaged(NotificationService $notifier, string $event, string $title, array $blocks, string $dedupPrefix): void
    {
        $sep = "\n---\n";
        $pages = [];
        $current = '';
        foreach ($blocks as $block) {
            $candidate = $current === '' ? $block : $current . $sep . $block;
            if ($current !== '' && strlen($candidate) > 3600) {
                $pages[] = $current;
                $current = $block;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') $pages[] = $current;

        $ts = now()->format('YmdHis');
        foreach ($pages as $i => $page) {
            $pageTitle = count($pages) > 1 ? $title . sprintf('（%d/%d）', $i + 1, count($pages)) : $title;
            $notifier->dispatch($event, [
                'title' => $pageTitle,
                'content' => $page,
                'dedup_key' => "{$dedupPrefix}_{$ts}_{$i}",
            ]);
        }
    }

    public function handle(NotificationService $notifier): int
    {
        $dry = (bool) $this->option('dry');

        // 1. 收集提前天数
        $webhooks = WebhookConfig::where('is_active', 1)->get();
        $allDays = [];
        $hasExpired = false;
        $hasLowBalance = false;
        $balanceThreshold = 50.0;

        foreach ($webhooks as $w) {
            $events = $w->events ?? [];
            $expiring = $events['subscription_expiring'] ?? null;
            if ($expiring && !empty($expiring['enabled'])) {
                foreach (($expiring['days'] ?? [7, 3, 2, 1]) as $d) {
                    $allDays[] = (int) $d;
                }
            }
            if (!empty($events['subscription_expired']['enabled'])) {
                $hasExpired = true;
            }
            if (!empty($events['customer_low_balance']['enabled'])) {
                $hasLowBalance = true;
                $t = $events['customer_low_balance']['threshold'] ?? 50;
                $balanceThreshold = max($balanceThreshold, (float) $t);
            }
        }

        $allDays = array_unique($allDays);
        sort($allDays);

        // 2. 过期提醒（按客户聚合）
        $notified = 0;
        $allExpiring = collect();
        foreach ($allDays as $days) {
            $target = now()->addDays($days)->startOfDay();
            $targetEnd = now()->addDays($days)->endOfDay();

            $subs = Subscription::with([
                'customer:id,customer_name,sales_person,balance,phone',
                'proxyIp:id,asset_name,ip_address,country_name',
            ])
                ->where('status', 'active')
                ->whereBetween('expires_at', [$target, $targetEnd])
                ->get();

            $this->line("提前 {$days} 天：找到 {$subs->count()} 条待提醒");

            foreach ($subs as $sub) {
                $allExpiring->push([
                    'sub' => $sub,
                    'days' => $days,
                ]);
            }
        }

        // Group by customer, then by sales_person → days
        $byCustomer = $allExpiring->groupBy(fn($item) => $item['sub']->customer_id);

        if ($dry) {
            foreach ($byCustomer as $customerId => $items) {
                $customer = $items->first()['sub']->customer;
                $this->line("  [DRY] 客户={$customer?->customer_name} 到期数={$items->count()}");
            }
        }

        if (!$dry && $byCustomer->isNotEmpty()) {
            // 按销售 → 天数 → 客户 组织
            $bySales = [];
            foreach ($byCustomer as $customerId => $items) {
                $customer = $items->first()['sub']->customer;
                if (!$customer) continue;
                $salesPerson = $customer->sales_person ?: '未分配';
                foreach ($items->groupBy('days') as $days => $dayItems) {
                    $bySales[$salesPerson][$days][] = [
                        'customer' => $customer,
                        'items' => $dayItems,
                    ];
                }
                $notified++;
            }

            // 按天数排序，生成每个 "销售+天数" 的消息块
            $salesBlocks = [];
            foreach ($bySales as $salesPerson => $dayGroups) {
                ksort($dayGroups);
                foreach ($dayGroups as $days => $customers) {
                    $totalCustomerCount = count($customers);
                    $header = sprintf("**%s**的%d位客户**%d天后**到期", $salesPerson, $totalCustomerCount, $days);
                    $customerLines = [];
                    foreach ($customers as $entry) {
                        $c = $entry['customer'];
                        $ips = $entry['items']->map(function ($item) {
                            $ip = $item['sub']->proxyIp;
                            return ($ip?->country_name ?? '') . ' ' . ($ip?->ip_address ?? '-');
                        })->join("\n");
                        $customerLines[] = sprintf(
                            "客户：**%s**\n手机号：%s\n到期数量：%d条\n%s",
                            $c->customer_name,
                            $c->phone ?: '未填写',
                            $entry['items']->count(),
                            $ips
                        );
                    }
                    $salesBlocks[] = $header . "\n" . implode("\n---\n", $customerLines);
                }
            }

            $totalCustomers = $byCustomer->count();
            $this->dispatchPaged($notifier, 'subscription_expiring',
                sprintf('IP到期提醒：%d位客户', $totalCustomers),
                $salesBlocks, 'expiring_v3');
        }

        // 3. 已过期
        if ($hasExpired) {
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();
            $expired = Subscription::with(['customer:id,customer_name', 'proxyIp:id,asset_name,ip_address'])
                ->where('status', 'active')
                ->whereBetween('expires_at', [$todayStart, $todayEnd])
                ->where('expires_at', '<', now())
                ->get();

            $this->line("今日已过期：{$expired->count()} 条");
            if (!$dry && $expired->count() > 0) {
                $expiredByCustomer = $expired->groupBy(fn($s) => $s->customer_id);
                $expBlocks = [];
                foreach ($expiredByCustomer as $cid => $subs) {
                    $c = $subs->first()->customer;
                    $ipList = $subs->map(fn($s) => "  - " . ($s->proxyIp?->asset_name ?: $s->proxyIp?->ip_address ?: '-'))->join("\n");
                    $expBlocks[] = sprintf("> **%s** %d条\n%s", $c?->customer_name ?? "#{$cid}", $subs->count(), $ipList);
                }
                $this->dispatchPaged($notifier, 'subscription_expired',
                    sprintf('❌ 今日已过期：%d条（%d位客户）', $expired->count(), $expiredByCustomer->count()),
                    $expBlocks, 'expired_summary');
            }
        }

        // 4. 低余额
        if ($hasLowBalance) {
            $lowBalanceCustomers = Customer::where('status', 1)
                ->where('balance', '<', $balanceThreshold)
                ->where('balance', '>=', 0)
                ->get();

            $this->line("低余额客户（< {$balanceThreshold}）：{$lowBalanceCustomers->count()} 条");
            if (!$dry && $lowBalanceCustomers->count() > 0) {
                $balanceBlocks = $lowBalanceCustomers->map(fn($c) => sprintf(
                    "> **%s**（%s）余额 ¥%.2f",
                    $c->customer_name,
                    $c->sales_person ?? '未分配',
                    (float) $c->balance
                ))->toArray();
                $this->dispatchPaged($notifier, 'customer_low_balance',
                    sprintf('💰 余额不足：%d位客户余额低于¥%.0f', $lowBalanceCustomers->count(), $balanceThreshold),
                    $balanceBlocks, 'low_balance_summary');
            }
        }

        // Auto-invalidate expired test pool IPs
        $expiredTestIps = \App\Models\ProxyIp::where('is_test_pool', true)
            ->where(function ($q) {
                // IP's own expiry
                $q->where('upstream_expires_at', '<=', now())
                  // OR has any subscription that expired
                  ->orWhereHas('subscriptions', function ($sq) {
                      $sq->where('expires_at', '<=', now())
                         ->whereIn('status', ['active', 'cancelled']);
                  });
            })
            ->get();

        foreach ($expiredTestIps as $ip) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($ip) {
                $ip->update([
                    'status' => 'expired',
                    'is_test_pool' => false,
                    'assigned_customer_id' => null,
                ]);
                // Expire ALL active subscriptions for this IP
                \App\Models\Subscription::where('proxy_ip_id', $ip->id)
                    ->where('status', 'active')
                    ->update(['status' => 'expired']);
            });
        }

        if ($expiredTestIps->count() > 0) {
            $this->info("Auto-expired {$expiredTestIps->count()} test pool IPs");
        }

        $this->info('✓ 检查完成' . ($dry ? '（试运行）' : ''));
        return self::SUCCESS;
    }

}
