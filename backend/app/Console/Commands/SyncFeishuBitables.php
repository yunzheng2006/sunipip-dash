<?php

namespace App\Console\Commands;

use App\Models\FeishuSyncConfig;
use App\Services\Feishu\FeishuSyncService;
use Illuminate\Console\Command;

/**
 * 定时同步所有活跃的飞书多维表格配置
 *
 * php artisan feishu:sync          # 同步全部
 * php artisan feishu:sync --id=1   # 只同步指定配置
 */
class SyncFeishuBitables extends Command
{
    protected $signature = 'feishu:sync {--id= : 只同步指定配置 ID}';
    protected $description = '同步飞书多维表格';

    public function handle(FeishuSyncService $service): int
    {
        $query = FeishuSyncConfig::where('is_active', 1);
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $configs = $query->with('customer:id,customer_name')->get();
        if ($configs->isEmpty()) {
            $this->info('无活跃配置');
            return self::SUCCESS;
        }

        foreach ($configs as $config) {
            $this->line("同步: {$config->name} (客户={$config->customer?->customer_name})");
            $result = $service->sync($config);
            $this->info(sprintf(
                "  创建 %d / 更新 %d / 未变 %d / 删除 %d%s",
                $result['created'], $result['updated'], $result['unchanged'], $result['deleted'],
                $result['errors'] ? ' | 错误: ' . implode('; ', $result['errors']) : ''
            ));
        }

        return self::SUCCESS;
    }
}
