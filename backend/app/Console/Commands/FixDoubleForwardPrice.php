<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDoubleForwardPrice extends Command
{
    protected $signature = 'fix:double-forward-price {--fix : 执行修复，默认 dry-run}';

    protected $description = '修复 price 被 AttachForwardJob 重复加了 forward_fee 的订阅';

    public function handle(): int
    {
        $isDryRun = !$this->option('fix');
        $this->info($isDryRun ? '[DRY RUN] 预览模式' : '[FIX] 执行模式');

        // 通过 SparkOrder.request_data 精确判断：
        // 条件1: SparkOrder 里有 forward_plan_id（下单时已含中转费）
        // 条件2: subscription.price == SparkOrder.sale_price + forward_fee（说明被重复加了）
        $subs = DB::table('subscriptions as s')
            ->join('forward_rules as f', 'f.subscription_id', '=', 's.id')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->join('proxy_ips as p', 'p.id', '=', 's.proxy_ip_id')
            ->join('spark_instances as si', 'si.proxy_ip_id', '=', 'p.id')
            ->join('spark_orders as so', 'so.id', '=', 'si.spark_order_id')
            ->where('f.forward_fee', '>', 0)
            ->select(
                's.id as sub_id', 's.price', 's.status', 's.customer_id', 's.expires_at',
                'c.customer_name',
                'f.forward_fee', 'f.status as fwd_status',
                'so.request_data'
            )
            ->orderBy('s.id')
            ->get();

        $affected = [];

        foreach ($subs as $row) {
            $rd = json_decode($row->request_data, true);
            if (!is_array($rd)) continue;

            $orderSalePrice = isset($rd['sale_price']) ? (float) $rd['sale_price'] : null;
            $orderFwdPlanId = $rd['forward_plan_id'] ?? null;

            if (!$orderFwdPlanId || $orderSalePrice === null) continue;

            // sale_price 已含中转费，正确的 price 应该等于 sale_price
            // 如果 price == sale_price + forward_fee，说明被 AttachForwardJob 多加了一次
            if (abs($row->price - ($orderSalePrice + $row->forward_fee)) < 0.01) {
                $affected[] = (object) [
                    'sub_id' => $row->sub_id,
                    'customer_name' => $row->customer_name,
                    'current_price' => $row->price,
                    'order_sale_price' => $orderSalePrice,
                    'forward_fee' => $row->forward_fee,
                    'expected_price' => $orderSalePrice,
                    'status' => $row->status,
                    'fwd_status' => $row->fwd_status,
                    'expires_at' => $row->expires_at,
                ];
            }
        }

        if (empty($affected)) {
            $this->info('未找到受影响的订阅');
            return 0;
        }

        $this->info("找到 " . count($affected) . " 条受影响的订阅：\n");

        $rows = [];
        foreach ($affected as $r) {
            $rows[] = [
                $r->sub_id,
                $r->customer_name,
                $r->current_price,
                $r->order_sale_price,
                $r->forward_fee,
                $r->expected_price,
                $r->status,
                $r->fwd_status,
            ];
        }

        $this->table(
            ['Sub#', '客户', '当前price', 'SparkOrder售价', 'forward_fee', '应为price', '订阅状态', '转发状态'],
            $rows
        );

        $totalOver = array_sum(array_map(fn($r) => $r->forward_fee, $affected));
        $activeCount = count(array_filter($affected, fn($r) => $r->status === 'active'));
        $this->info("\nprice 总共多算: ¥{$totalOver}");
        $this->info("其中 active: {$activeCount} 条");

        if ($isDryRun) {
            $this->newLine();
            $this->warn('使用 --fix 执行修复');
            return 1;
        }

        foreach ($affected as $r) {
            DB::table('subscriptions')
                ->where('id', $r->sub_id)
                ->update(['price' => $r->expected_price]);
            $this->info("  Sub#{$r->sub_id}: price {$r->current_price} → {$r->expected_price}");
        }

        $this->info("\n修复完成");
        return 0;
    }
}
