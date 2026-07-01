<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreBalanceDeducted extends Command
{
    protected $signature = 'fix:restore-balance-deducted {--dry-run : 仅预览，不修改}';
    protected $description = '根据交易记录重新推导所有订阅的 balance_deducted 值（复原到迁移后状态）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 预览模式】' : '【执行模式】');
        $this->newLine();

        $totalSubs = DB::table('subscriptions')->count();
        $currentTrue = DB::table('subscriptions')->where('balance_deducted', true)->count();
        $currentFalse = DB::table('subscriptions')->where('balance_deducted', false)->count();

        $this->info("当前订阅总数: {$totalSubs}");
        $this->info("  balance_deducted=true:  {$currentTrue}");
        $this->info("  balance_deducted=false: {$currentFalse}");
        $this->newLine();

        // 规则1: 有直接关联的 purchase 交易
        $rule1 = DB::table('subscriptions as s')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.related_id', 's.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->where('t.type', 'purchase')
                  ->where('t.amount', '<', 0);
            })
            ->pluck('s.id');

        // 规则2: 有直接关联的 deduction 交易
        $rule2 = DB::table('subscriptions as s')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.related_id', 's.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->where('t.type', 'deduction')
                  ->where('t.amount', '<', 0);
            })
            ->pluck('s.id');

        // 规则3: 非管理员创建，有时间临近的 purchase/deduction 交易（5分钟内）
        $rule3 = DB::table('subscriptions as s')
            ->whereNull('s.admin_set_price')
            ->where('s.is_test', false)
            ->whereNotIn('s.id', $rule1->merge($rule2)->unique())
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.customer_id', 's.customer_id')
                  ->whereIn('t.type', ['purchase', 'deduction'])
                  ->where('t.amount', '<', 0)
                  ->whereRaw('t.created_at BETWEEN DATE_SUB(s.created_at, INTERVAL 5 MINUTE) AND DATE_ADD(s.created_at, INTERVAL 5 MINUTE)');
            })
            ->pluck('s.id');

        // 规则4: 管理员创建，通过 Spark 余额扣款
        $rule4Sql = "
            SELECT DISTINCT s.id
            FROM subscriptions s
            INNER JOIN spark_instances si ON si.proxy_ip_id = s.proxy_ip_id
            INNER JOIN spark_orders so ON so.id = si.spark_order_id
            WHERE s.admin_set_price IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(so.request_data, '$.payment_method')) = 'balance'
        ";
        $rule4 = collect(DB::select($rule4Sql))->pluck('id');

        // 规则5: 管理员创建，有匹配的未关联 purchase 交易
        $rule5 = DB::table('subscriptions as s')
            ->whereNotNull('s.admin_set_price')
            ->where('s.is_test', false)
            ->whereNotIn('s.id', $rule1->merge($rule2)->merge($rule4)->unique())
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.customer_id', 's.customer_id')
                  ->where('t.type', 'purchase')
                  ->where('t.amount', '<', 0)
                  ->whereNull('t.related_id')
                  ->where('t.description', 'like', '开通订单扣费%')
                  ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, t.created_at, s.started_at)) <= 300');
            })
            ->pluck('s.id');

        $shouldBeTrue = $rule1->merge($rule2)->merge($rule3)->merge($rule4)->merge($rule5)->unique();

        $this->info('推导规则：');
        $this->table(
            ['规则', '描述', '匹配数'],
            [
                ['1', '有直接关联的 purchase 交易', $rule1->count()],
                ['2', '有直接关联的 deduction 交易', $rule2->count()],
                ['3', '非管理员+时间临近交易(5分钟)', $rule3->count()],
                ['4', '管理员+Spark余额扣款', $rule4->count()],
                ['5', '管理员+匹配未关联purchase交易', $rule5->count()],
            ]
        );

        $this->newLine();
        $this->info("推导结果: {$shouldBeTrue->count()} 条应为 true，" . ($totalSubs - $shouldBeTrue->count()) . " 条应为 false");

        $needSetTrue = DB::table('subscriptions')
            ->whereIn('id', $shouldBeTrue)
            ->where('balance_deducted', false)
            ->pluck('id');

        $needSetFalse = DB::table('subscriptions')
            ->whereNotIn('id', $shouldBeTrue->isEmpty() ? [0] : $shouldBeTrue)
            ->where('balance_deducted', true)
            ->pluck('id');

        $this->newLine();
        $this->info("需要修改:");
        $this->info("  false → true: {$needSetTrue->count()} 条");
        $this->info("  true → false: {$needSetFalse->count()} 条");

        if ($needSetTrue->isEmpty() && $needSetFalse->isEmpty()) {
            $this->newLine();
            $this->info('数据已正确，无需修改。');
            return 0;
        }

        if ($needSetTrue->isNotEmpty()) {
            $this->newLine();
            $this->info('将设为 true 的订阅（前20条）：');
            $samples = DB::table('subscriptions')
                ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
                ->whereIn('subscriptions.id', $needSetTrue->take(20))
                ->select('subscriptions.id', 'customers.customer_name', 'subscriptions.price', 'subscriptions.status', 'subscriptions.admin_set_price')
                ->get();
            $this->table(
                ['订阅ID', '客户', '价格', '状态', '管理员定价'],
                $samples->map(fn($r) => [$r->id, $r->customer_name, '¥'.$r->price, $r->status, $r->admin_set_price ? '¥'.$r->admin_set_price : '-'])
            );
        }

        if ($needSetFalse->isNotEmpty()) {
            $this->newLine();
            $this->info('将设为 false 的订阅（前20条）：');
            $samples = DB::table('subscriptions')
                ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
                ->whereIn('subscriptions.id', $needSetFalse->take(20))
                ->select('subscriptions.id', 'customers.customer_name', 'subscriptions.price', 'subscriptions.status', 'subscriptions.admin_set_price')
                ->get();
            $this->table(
                ['订阅ID', '客户', '价格', '状态', '管理员定价'],
                $samples->map(fn($r) => [$r->id, $r->customer_name, '¥'.$r->price, $r->status, $r->admin_set_price ? '¥'.$r->admin_set_price : '-'])
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN 完成，未修改任何数据。去掉 --dry-run 执行修复。');
            return 0;
        }

        if (!$this->confirm('确认按以上推导结果修复 balance_deducted？')) {
            $this->info('已取消。');
            return 0;
        }

        $updatedTrue = 0;
        $updatedFalse = 0;

        if ($needSetTrue->isNotEmpty()) {
            foreach ($needSetTrue->chunk(500) as $chunk) {
                $updatedTrue += DB::table('subscriptions')
                    ->whereIn('id', $chunk)
                    ->update(['balance_deducted' => true]);
            }
        }

        if ($needSetFalse->isNotEmpty()) {
            foreach ($needSetFalse->chunk(500) as $chunk) {
                $updatedFalse += DB::table('subscriptions')
                    ->whereIn('id', $chunk)
                    ->update(['balance_deducted' => false]);
            }
        }

        $this->newLine();
        $this->info("修复完成: {$updatedTrue} 条设为 true，{$updatedFalse} 条设为 false");

        return 0;
    }
}
