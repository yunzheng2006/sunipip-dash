<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 修复客户缺失的 invited_by（业务归属用户ID）
 *
 * 两阶段修复：
 *   阶段1：找所有 sales_person 非空但 invited_by 为空的客户，
 *          通过 sales_person 名称查找对应 User，回填 invited_by
 *   阶段2：找所有 referred_by_customer 非空但 invited_by 为空的客户，
 *          如果其推荐人有 invited_by，则继承推荐人的 invited_by
 */
class FixInvitedBy extends Command
{
    protected $signature = 'customers:fix-invited-by
        {--dry-run : 只预览不执行}';

    protected $description = '修复客户缺失的 invited_by（根据 sales_person 和推荐链）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 预加载 name→id 映射
        $userMap = User::pluck('id', 'name')->toArray();

        // ── 阶段1：根据 sales_person 修复 invited_by ──
        $this->info('=== 阶段1：根据 sales_person 修复 invited_by ===');

        $phase1 = Customer::where(function ($q) {
                $q->whereNotNull('sales_person')->where('sales_person', '!=', '');
            })
            ->whereNull('invited_by')
            ->orderBy('id')
            ->get(['id', 'customer_name', 'sales_person', 'invited_by']);

        if ($phase1->isEmpty()) {
            $this->info('阶段1：没有需要修复的客户。');
        } else {
            $this->info("阶段1：找到 {$phase1->count()} 个客户有 sales_person 但缺少 invited_by");
            $this->newLine();

            $fixed1 = 0;
            $notFound1 = 0;
            $rows1 = [];

            foreach ($phase1 as $customer) {
                $userId = $userMap[$customer->sales_person] ?? null;

                $rows1[] = [
                    $customer->id,
                    mb_substr($customer->customer_name, 0, 20),
                    $customer->sales_person,
                    $userId ?? '(未找到用户)',
                ];

                if ($userId) {
                    if (!$dryRun) {
                        $customer->invited_by = $userId;
                        $customer->save();
                    }
                    $fixed1++;
                } else {
                    $notFound1++;
                }
            }

            $this->table(['ID', '客户名', 'sales_person', 'invited_by'], $rows1);
            $this->newLine();

            if ($dryRun) {
                $this->warn("[DRY RUN] 阶段1：将修复 {$fixed1} 个客户。");
            } else {
                $this->info("阶段1：已修复 {$fixed1} 个客户。");
            }

            if ($notFound1 > 0) {
                $this->warn("阶段1：{$notFound1} 个客户的 sales_person 找不到对应的管理员用户。");
            }
        }

        $this->newLine();

        // ── 阶段2：根据推荐链级联修复 invited_by + sales_person ──
        $this->info('=== 阶段2：根据推荐链级联修复 invited_by + sales_person ===');

        $reverseUserMap = array_flip($userMap); // id → name

        $fixed2 = 0;
        $rows2 = [];
        $passes = 0;
        $maxPasses = 50;

        do {
            $passes++;
            $batchFixed = 0;

            $phase2 = Customer::whereNotNull('referred_by_customer')
                ->where(function ($q) {
                    $q->whereNull('invited_by')
                      ->orWhereNull('sales_person')
                      ->orWhere('sales_person', '');
                })
                ->orderBy('id')
                ->get(['id', 'customer_name', 'referred_by_customer', 'invited_by', 'sales_person']);

            if ($phase2->isEmpty()) {
                break;
            }

            $referrerIds = $phase2->pluck('referred_by_customer')->unique();
            $referrerData = Customer::whereIn('id', $referrerIds)
                ->get(['id', 'invited_by', 'sales_person'])
                ->keyBy('id');

            foreach ($phase2 as $customer) {
                $referrer = $referrerData->get($customer->referred_by_customer);
                if (!$referrer) continue;

                $changed = false;

                if (!$customer->invited_by && $referrer->invited_by) {
                    if (!$dryRun) $customer->invited_by = $referrer->invited_by;
                    $changed = true;
                }

                if (empty($customer->sales_person) && !empty($referrer->sales_person)) {
                    if (!$dryRun) $customer->sales_person = $referrer->sales_person;
                    $changed = true;
                } elseif (empty($customer->sales_person) && $referrer->invited_by) {
                    $name = $reverseUserMap[$referrer->invited_by] ?? null;
                    if ($name) {
                        if (!$dryRun) $customer->sales_person = $name;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $rows2[] = [
                        $customer->id,
                        mb_substr($customer->customer_name, 0, 20),
                        $customer->referred_by_customer,
                        $customer->invited_by ?: $referrer->invited_by,
                        $customer->sales_person ?: $referrer->sales_person,
                        "第{$passes}轮",
                    ];
                    if (!$dryRun) $customer->save();
                    $batchFixed++;
                    $fixed2++;
                }
            }
        } while ($batchFixed > 0 && $passes < $maxPasses);

        if (empty($rows2)) {
            $this->info('阶段2：没有需要通过推荐链修复的客户。');
        } else {
            $this->table(['ID', '客户名', '推荐人ID', 'invited_by', 'sales_person', '轮次'], $rows2);
            $this->newLine();

            if ($dryRun) {
                $this->warn("[DRY RUN] 阶段2：将修复 {$fixed2} 个客户（共 {$passes} 轮）。");
            } else {
                $this->info("阶段2：已修复 {$fixed2} 个客户（共 {$passes} 轮）。");
            }
        }

        $this->newLine();

        // ── 汇总 ──
        $total = ($phase1->isEmpty() ? 0 : ($dryRun ? $fixed1 : $fixed1)) + $fixed2;
        if ($dryRun) {
            $this->warn("[DRY RUN] 总计将修复 {$total} 个客户的 invited_by。去掉 --dry-run 以实际执行。");
        } else {
            $this->info("总计修复 {$total} 个客户的 invited_by。");
        }

        // 检查是否还有遗留
        $remaining = Customer::where(function ($q) {
                $q->whereNotNull('sales_person')->where('sales_person', '!=', '');
            })
            ->whereNull('invited_by')
            ->count();

        if ($remaining > 0) {
            $this->warn("仍有 {$remaining} 个客户有 sales_person 但 invited_by 为空（可能 sales_person 对应的用户不存在）。");
        }

        return 0;
    }
}
