<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 修复自助购买 price 被 duration 双重相乘的订阅
 *
 * 根因：CheckoutService 传给 SparkProvisionService 的 sale_price 已含时长
 * （月价×duration），provision 侧又 × durationMonths，导致 duration≥2 的
 * 自助订单 price = 实付 × duration（如实付 ¥897 买 12 个月，price 落 ¥10764）。
 * 正确 price 应等于 request_data.sale_price（该字段即客户实付总价）。
 */
class FixDurationSquaredPrice extends Command
{
    protected $signature = 'fix:duration-squared-price {--fix : 执行修复，默认 dry-run}';

    protected $description = '修复自助购买 price 被 duration 双重相乘的订阅';

    public function handle(): int
    {
        $isDryRun = !$this->option('fix');
        $this->info($isDryRun ? '[DRY RUN] 预览模式' : '[FIX] 执行模式');

        $rows = DB::table('subscriptions as s')
            ->join('spark_instances as si', 'si.proxy_ip_id', '=', 's.proxy_ip_id')
            ->join('spark_orders as so', 'so.id', '=', 'si.spark_order_id')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('so.method', 'CreateProxy')
            ->where('so.duration', '>', 1)
            ->where('so.request_data', 'LIKE', '%自助下单%')
            ->select('s.id as sub_id', 's.price', 's.status', 's.duration', 's.unit',
                's.expires_at', 'c.customer_name', 'so.id as order_id', 'so.duration as order_duration',
                'so.request_data')
            ->orderBy('s.id')
            ->get();

        $affected = [];
        $anomalies = [];

        foreach ($rows as $r) {
            $rd = json_decode($r->request_data, true);
            if (!is_array($rd) || !isset($rd['sale_price'])) continue;

            $paidTotal = (float) $rd['sale_price']; // 自助路径此字段 = 客户实付总价（含中转）
            $months = max((int) $r->order_duration, 1);
            $inflated = round($paidTotal * $months, 2);

            if (abs((float) $r->price - $paidTotal) < 0.01) {
                continue; // price 正确
            }

            if (abs((float) $r->price - $inflated) < 0.01) {
                // 精确匹配 双重相乘
                $affected[] = (object) [
                    'sub_id' => $r->sub_id,
                    'customer' => $r->customer_name,
                    'price' => (float) $r->price,
                    'expected' => $paidTotal,
                    'months' => $months,
                    'status' => $r->status,
                    'order_id' => $r->order_id,
                ];
            } else {
                // price 与两种口径都不符（可能叠加了转发重复加价等），列出人工核查
                $anomalies[] = (object) [
                    'sub_id' => $r->sub_id,
                    'customer' => $r->customer_name,
                    'price' => (float) $r->price,
                    'paid_total' => $paidTotal,
                    'inflated' => $inflated,
                    'status' => $r->status,
                    'order_id' => $r->order_id,
                ];
            }
        }

        if (!empty($affected)) {
            $this->info("\n确认双重相乘（price = 实付 × duration），共 " . count($affected) . " 条：");
            $this->table(
                ['Sub#', '客户', '当前price', '应为(实付)', '月数', '状态', 'Order#'],
                array_map(fn($a) => [(string) $a->sub_id, $a->customer, (string) $a->price, (string) $a->expected, (string) $a->months, $a->status, (string) $a->order_id], $affected)
            );
            $totalDiff = array_sum(array_map(fn($a) => $a->price - $a->expected, $affected));
            $this->info('price 合计虚高: ¥' . round($totalDiff, 2));
        } else {
            $this->info('未发现精确匹配双重相乘的订阅');
        }

        if (!empty($anomalies)) {
            $this->warn("\n口径不符、需人工核查（不会被 --fix 修改），共 " . count($anomalies) . " 条：");
            $this->table(
                ['Sub#', '客户', '当前price', '实付', '双乘值', '状态', 'Order#'],
                array_map(fn($a) => [(string) $a->sub_id, $a->customer, (string) $a->price, (string) $a->paid_total, (string) $a->inflated, $a->status, (string) $a->order_id], $anomalies)
            );
        }

        if ($isDryRun) {
            $this->newLine();
            $this->warn('使用 --fix 仅修复上方精确匹配的订阅');
            return 1;
        }

        foreach ($affected as $a) {
            DB::table('subscriptions')->where('id', $a->sub_id)->update(['price' => $a->expected]);
            $this->info("  Sub#{$a->sub_id}: price {$a->price} → {$a->expected}");
        }
        $this->info("\n修复完成（" . count($affected) . " 条）");
        return 0;
    }
}
