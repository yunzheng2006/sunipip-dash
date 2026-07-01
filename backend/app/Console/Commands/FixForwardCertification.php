<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ForwardRule;
use Illuminate\Console\Command;

class FixForwardCertification extends Command
{
    protected $signature = 'fix:forward-certification {--dry-run : 仅显示将被修改的客户，不执行}';
    protected $description = '为已有转发记录的客户自动开启中转权限 (forward_certified)';

    public function handle(): int
    {
        $this->info('查找已有活跃/待处理转发规则的客户...');

        $customerIds = ForwardRule::whereIn('forward_rules.status', ['active', 'pending'])
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->pluck('subscriptions.customer_id')
            ->unique()
            ->values();

        if ($customerIds->isEmpty()) {
            $this->info('未找到符合条件的客户。');
            return 0;
        }

        $toUpdate = Customer::whereIn('id', $customerIds)
            ->where(function ($q) {
                $q->where('forward_certified', false)
                  ->orWhereNull('forward_certified');
            })
            ->get();

        if ($toUpdate->isEmpty()) {
            $this->info("找到 {$customerIds->count()} 个有转发记录的客户，但都已认证。无需更新。");
            return 0;
        }

        $this->info("找到 {$toUpdate->count()} 个需要开启 forward_certified 的客户：");

        $this->table(
            ['ID', '客户名称', '用户名'],
            $toUpdate->map(fn ($c) => [$c->id, $c->customer_name, $c->username])->toArray()
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run 模式，未执行更新。');
            return 0;
        }

        $updated = Customer::whereIn('id', $toUpdate->pluck('id'))
            ->update([
                'forward_certified' => true,
                'forward_certified_at' => now(),
                'forward_certified_by' => 1,
            ]);

        $this->info("已更新 {$updated} 个客户的 forward_certified = true。");

        return 0;
    }
}
