<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 修复 Spark 开单后未正确分配客户 / 未创建订阅的孤立 IP。
 *
 * 触发原因：旧版 SparkController::provision() 未接收 customer_id / country 等字段，
 * 导致部分历史 ProxyIp 记录 assigned_customer_id 为空、无 Subscription。
 *
 * 典型用法：
 *   php artisan spark:fix-orphans                              # 预览
 *   php artisan spark:fix-orphans --apply                      # 自动修复 request_data 含 customer_id 的记录
 *   php artisan spark:fix-orphans --apply --proxy-ip=12 --customer=5 --price=80 --duration=1 --unit=3
 */
class FixSparkOrphans extends Command
{
    protected $signature = 'spark:fix-orphans
        {--apply : 实际写库，默认仅预览}
        {--proxy-ip= : 只处理指定的 ProxyIp ID}
        {--customer= : 手动指定客户 ID（用于 request_data 中无 customer_id 的历史记录）}
        {--price= : 手动指定订阅月单价（元）}
        {--duration=1 : 订阅时长数值}
        {--unit=3 : 1=天 2=周 3=月 4=年}
        {--country-code= : 手动指定国家代码（ISO 2 位）}
        {--country-name= : 手动指定国家中文名}';

    protected $description = '修复 Spark 开单但未分配客户 / 未创建订阅的孤立 IP';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyId = $this->option('proxy-ip');
        $manualCustomer = $this->option('customer');
        $manualPrice = $this->option('price');
        $manualCountryCode = $this->option('country-code');
        $manualCountryName = $this->option('country-name');
        $optDuration = (int) $this->option('duration');
        $optUnit = (int) $this->option('unit');

        $query = ProxyIp::where('source_name', '斯帕克')
            ->whereNotNull('spark_instance_id')
            ->where('status', '!=', 'released')
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')
                    ->orWhereDoesntHave('subscriptions', fn ($sq) => $sq->whereIn('status', ['active', 'expired']));
            });

        if ($onlyId) {
            $query->where('id', $onlyId);
        }

        $orphans = $query->orderBy('id')->get();

        if ($orphans->isEmpty()) {
            $this->info('✓ 没有发现需要修复的孤立 Spark IP');
            return self::SUCCESS;
        }

        $this->info("发现 {$orphans->count()} 条待处理的 Spark IP");
        $this->newLine();

        $toFix = [];
        $skipped = 0;

        foreach ($orphans as $ip) {
            $instance = SparkInstance::where('proxy_ip_id', $ip->id)->first();
            $sparkOrder = $instance ? SparkOrder::find($instance->spark_order_id) : null;
            $reqData = $sparkOrder?->request_data ?? [];

            $customerId = $manualCustomer ?: ($reqData['customer_id'] ?? null);
            $price = $manualPrice !== null
                ? (float) $manualPrice
                : (float) ($reqData['sale_price'] ?? 0);
            $duration = $optDuration ?: (int) ($reqData['duration'] ?? 1);
            $unit = $optUnit ?: (int) ($reqData['unit'] ?? 3);
            $countryCode = $manualCountryCode ?: ($reqData['country_code'] ?? $ip->country_code);
            $countryName = $manualCountryName ?: ($reqData['country_cn'] ?? $ip->country_name);
            $productName = $reqData['product_name'] ?? '';

            if (!$customerId) {
                $this->warn(sprintf(
                    '  [跳过] ProxyIp#%d %s:%d  无 customer_id（SparkOrder#%s request_data 不含，也未指定 --customer）',
                    $ip->id, $ip->ip_address, $ip->port, $sparkOrder?->id ?? '?'
                ));
                $skipped++;
                continue;
            }

            $customer = Customer::find($customerId);
            if (!$customer) {
                $this->error("  [跳过] ProxyIp#{$ip->id} 客户 ID {$customerId} 不存在");
                $skipped++;
                continue;
            }

            $toFix[] = compact('ip', 'instance', 'customer', 'price', 'duration', 'unit', 'countryCode', 'countryName', 'productName');

            $this->line(sprintf(
                '  → ProxyIp#%-4d %-21s  客户=%s  国家=%s  价格=%.2f  时长=%d%s',
                $ip->id,
                $ip->ip_address . ':' . $ip->port,
                $customer->customer_name,
                $countryName ?: ($countryCode ?: '-'),
                $price,
                $duration,
                ['', '天', '周', '月', '年'][$unit] ?? ''
            ));
        }

        $this->newLine();
        $this->line("待修复: " . count($toFix) . "    跳过: {$skipped}");

        if (!$apply) {
            $this->warn('预览模式。加 --apply 实际写库。');
            return self::SUCCESS;
        }

        if (empty($toFix)) {
            return self::SUCCESS;
        }

        if (!$this->confirm('确认对以上记录写入修复？', false)) {
            $this->info('已取消');
            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($toFix as $row) {
            DB::transaction(function () use ($row) {
                /** @var ProxyIp $ip */
                $ip = $row['ip'];
                /** @var SparkInstance|null $instance */
                $instance = $row['instance'];
                /** @var Customer $customer */
                $customer = $row['customer'];

                $updates = [
                    'assigned_customer_id' => $customer->id,
                    'status' => 'assigned',
                ];
                if ($row['countryCode']) {
                    $updates['country_code'] = $row['countryCode'];
                }
                if ($row['countryName']) {
                    $updates['country_name'] = $row['countryName'];
                }

                // 重写默认/空的资产名为标准格式：客户-国家-IP
                $shouldRename = empty($ip->asset_name)
                    || str_starts_with($ip->asset_name, 'Spark-')
                    || ($row['productName'] && str_contains($ip->asset_name, $row['productName']));
                if ($shouldRename) {
                    $label = $row['countryName'] ?: ($row['countryCode'] ?: ($row['productName'] ?: 'IP'));
                    $updates['asset_name'] = "{$label}-{$ip->ip_address}";
                }

                $ip->update($updates);

                // 决定 expires_at
                $expiresAt = $instance?->expire_at ?: $ip->upstream_expires_at;
                if (!$expiresAt) {
                    $expiresAt = \App\Support\DurationHelper::addToDate(now(), $row['duration'], $row['unit']);
                }

                // 仅在确实没有 active 订阅时才补建
                $hasActive = Subscription::where('proxy_ip_id', $ip->id)
                    ->where('status', 'active')
                    ->exists();

                if (!$hasActive) {
                    Subscription::create([
                        'customer_id' => $customer->id,
                        'proxy_ip_id' => $ip->id,
                        'price' => $row['price'],
                        'duration' => $row['duration'],
                        'unit' => $row['unit'],
                        'started_at' => $ip->created_at ?: now(),
                        'expires_at' => $expiresAt,
                        'status' => 'active',
                        'created_by' => 1,
                        'remark' => 'fix-orphans 脚本补录（原 Spark 开单未分配）',
                    ]);
                }

                IpAssignmentLog::create([
                    'proxy_ip_id' => $ip->id,
                    'customer_id' => $customer->id,
                    'action' => 'assign',
                    'operated_by' => 1,
                    'remark' => 'spark:fix-orphans 脚本补录',
                    'created_at' => now(),
                ]);
            });

            $fixed++;
        }

        $this->newLine();
        $this->info("✓ 修复完成: 成功 {$fixed}  跳过 {$skipped}");
        return self::SUCCESS;
    }
}
