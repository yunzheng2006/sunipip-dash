<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkCountry;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

/**
 * 补齐 Spark 来源的 ProxyIp 缺失的 country_code / country_name。
 *
 * 数据来源（按优先级）：
 *   1. SparkOrder.request_data 中存的 country_code / country_cn
 *   2. 调用 Spark GetInstance API，从返回的 stateCode（格式 USA0CA）前 3 位提取
 *   3. 通过 area_country 表把 alpha-3 code 翻译成中文名
 *
 * 典型用法：
 *   php artisan spark:backfill-countries             # 预览
 *   php artisan spark:backfill-countries --apply     # 执行
 *   php artisan spark:backfill-countries --apply --call-api  # 对 request_data 没数据的也调 Spark API
 */
class BackfillSparkCountries extends Command
{
    protected $signature = 'spark:backfill-countries
        {--apply : 实际写库，默认仅预览}
        {--call-api : 对 request_data 中无 country 的 IP，调用 Spark GetInstance 补全}
        {--proxy-ip= : 仅处理指定 ID}';

    protected $description = '补齐 Spark 来源 ProxyIp 缺失的国家信息';

    public function handle(SparkApiService $spark): int
    {
        $apply = (bool) $this->option('apply');
        $callApi = (bool) $this->option('call-api');
        $onlyId = $this->option('proxy-ip');

        $query = ProxyIp::where('source_name', '斯帕克')
            ->where(function ($q) {
                $q->whereNull('country_name')
                    ->orWhere('country_name', '')
                    ->orWhereNull('country_code')
                    ->orWhere('country_code', '');
            });

        if ($onlyId) {
            $query->where('id', $onlyId);
        }

        $ips = $query->orderBy('id')->get();

        if ($ips->isEmpty()) {
            $this->info('✓ 没有需要补齐国家的 Spark IP');
            return self::SUCCESS;
        }

        $this->info("发现 {$ips->count()} 条 Spark IP 缺少国家信息");
        $this->newLine();

        $fixed = 0;
        $apiCalls = 0;
        $failed = 0;

        foreach ($ips as $ip) {
            $countryCode = null;
            $countryCn = null;
            $source = '';

            // 1. 从 SparkOrder.request_data 取
            $instance = SparkInstance::where('proxy_ip_id', $ip->id)->first();
            $sparkOrder = $instance ? SparkOrder::find($instance->spark_order_id) : null;
            $reqData = $sparkOrder?->request_data ?? [];

            if (!empty($reqData['country_code'])) {
                $countryCode = $reqData['country_code'];
                $countryCn = $reqData['country_cn'] ?? null;
                $source = 'request_data';
            }

            // 2. 从资产组的 country 字段读取（Spark 资产组通常配置了国家）
            if (!$countryCode && $ip->asset_group_id) {
                $assetGroup = \App\Models\IpAssetGroup::find($ip->asset_group_id);
                if ($assetGroup && $assetGroup->country_code) {
                    $countryCode = $assetGroup->country_code;
                    $countryCn = $assetGroup->country_name ?: null;
                    $source = 'asset_group';
                }
            }

            // 3. 调 Spark API（仅当开启 --call-api）
            if (!$countryCode && $callApi && $ip->spark_instance_id) {
                try {
                    $data = $spark->getInstance(['instanceId' => $ip->spark_instance_id]);
                    $apiCalls++;

                    // Spark 返回字段尝试多种可能名
                    $stateCode = $data['stateCode'] ?? $data['state_code'] ?? null;
                    if ($stateCode) {
                        $countryCode = substr($stateCode, 0, 3);
                        $source = 'spark_api(stateCode)';
                    } elseif (!empty($data['countryCode'])) {
                        $countryCode = $data['countryCode'];
                        $source = 'spark_api(countryCode)';
                    } elseif (!empty($data['country_code'])) {
                        $countryCode = $data['country_code'];
                        $source = 'spark_api(country_code)';
                    } elseif (!empty($data['country'])) {
                        $countryCode = $data['country'];
                        $source = 'spark_api(country)';
                    } else {
                        // 打印首次响应结构帮助调试
                        if ($apiCalls === 1) {
                            $this->line('  [调试] Spark GetInstance 响应字段: ' . implode(', ', array_keys($data)));
                        }
                    }
                    usleep(200000); // 200ms 限流
                } catch (\Exception $e) {
                    $this->warn("  [API错误] ProxyIp#{$ip->id}: {$e->getMessage()}");
                }
            }

            // 3. 根据 code 反查中文名
            if ($countryCode && !$countryCn) {
                $countryCn = SparkCountry::getNameByCode($countryCode) ?: $countryCode;
            }

            if (!$countryCode) {
                $this->warn(sprintf(
                    '  [跳过] ProxyIp#%d %s:%d  无法确定国家%s',
                    $ip->id, $ip->ip_address, $ip->port,
                    $callApi ? '' : '（加 --call-api 调用 Spark 补全）'
                ));
                $failed++;
                continue;
            }

            $this->line(sprintf(
                '  → ProxyIp#%-4d %-21s  %s → %s  [%s]',
                $ip->id,
                $ip->ip_address . ':' . $ip->port,
                $countryCode,
                $countryCn,
                $source
            ));

            if ($apply) {
                $updates = [
                    'country_code' => $countryCode,
                    'country_name' => $countryCn,
                ];

                // 顺便修正资产名（若包含明显的空国家）
                if ($ip->assigned_customer_id && $ip->asset_name) {
                    $customer = \App\Models\Customer::find($ip->assigned_customer_id);
                    if ($customer) {
                        $expected = "{$customer->customer_name}-{$countryCn}-{$ip->ip_address}";
                        if ($ip->asset_name !== $expected && (
                            str_contains($ip->asset_name, '--') ||  // 客户--IP（中间国家为空）
                            str_starts_with($ip->asset_name, 'Spark-')
                        )) {
                            $updates['asset_name'] = $expected;
                        }
                    }
                }

                $ip->update($updates);
            }

            $fixed++;
        }

        $this->newLine();
        $this->line("处理: 成功 {$fixed}  跳过 {$failed}" . ($callApi ? "  API调用 {$apiCalls}" : ''));

        if (!$apply) {
            $this->warn('预览模式。加 --apply 实际写库。');
        } else {
            $this->info('✓ 补齐完成');
        }

        return self::SUCCESS;
    }
}
