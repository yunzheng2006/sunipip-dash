<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 修复历史客户缺失的 sales_person（业务归属人）
 *
 * 逻辑：
 *   1. 找所有 sales_person 为空的客户
 *   2. 从 activity_logs 查谁创建了这个客户（action=create, subject_type=Customer）
 *   3. 用创建者的 name 填入 sales_person
 *   4. 如果日志里也查不到创建者，标记为 fallback 值
 */
class FixCustomerSalesPerson extends Command
{
    protected $signature = 'customers:fix-sales-person
        {--fallback= : 日志中查不到创建者时的默认归属人}
        {--dry-run : 只预览不执行}';

    protected $description = '修复历史客户缺失的业务归属人（sales_person）';

    public function handle(): int
    {
        $fallback = $this->option('fallback');
        $dryRun = $this->option('dry-run');

        $customers = Customer::whereNull('sales_person')
            ->orWhere('sales_person', '')
            ->orderBy('id')
            ->get(['id', 'customer_name', 'sales_person', 'created_at']);

        if ($customers->isEmpty()) {
            $this->info('没有需要修复的客户，所有客户都已有业务归属人。');
            return 0;
        }

        $this->info("找到 {$customers->count()} 个缺少业务归属人的客户：");
        $this->newLine();

        // 预加载所有用户 id→name 映射
        $userNames = User::pluck('name', 'id')->toArray();

        $fixed = 0;
        $notFound = 0;
        $rows = [];

        foreach ($customers as $customer) {
            // 从活动日志查创建者
            $log = ActivityLog::where('subject_type', 'Customer')
                ->where('subject_id', $customer->id)
                ->where('action', 'create')
                ->whereNotNull('user_id')
                ->first();

            $creatorName = null;
            $source = '-';

            if ($log && isset($userNames[$log->user_id])) {
                $creatorName = $userNames[$log->user_id];
                $source = "活动日志 (user #{$log->user_id})";
            } elseif ($fallback) {
                $creatorName = $fallback;
                $source = '默认值';
            }

            $rows[] = [
                $customer->id,
                mb_substr($customer->customer_name, 0, 20),
                $customer->created_at?->format('Y-m-d') ?? '-',
                $creatorName ?? '(未找到)',
                $source,
            ];

            if ($creatorName && !$dryRun) {
                $customer->sales_person = $creatorName;
                $customer->save();
                $fixed++;
            } elseif (!$creatorName) {
                $notFound++;
            }
        }

        $this->table(['ID', '客户名', '创建日期', '归属人', '来源'], $rows);
        $this->newLine();

        if ($dryRun) {
            $this->warn("[DRY RUN] 未执行任何修改。去掉 --dry-run 参数以实际执行。");
        } else {
            $this->info("已修复 {$fixed} 个客户的业务归属人。");
        }

        if ($notFound > 0) {
            $this->warn("{$notFound} 个客户在日志中查不到创建者。" .
                ($fallback ? '' : '可用 --fallback=姓名 指定默认归属人。'));
        }

        return 0;
    }
}
