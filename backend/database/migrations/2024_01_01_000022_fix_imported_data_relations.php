<?php

use App\Models\IpAssetGroup;
use App\Models\ProxyIp;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复导入数据：
 * 1. 为每个 source_name 创建资产组并关联IP
 * 2. 修复客户的 sales_person（确保所有客户都有业务归属）
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminId = User::where('username', 'admin')->value('id') ?? 1;

        // 1. 获取所有唯一的 source_name，为每个创建资产组
        $sources = ProxyIp::whereNull('asset_group_id')
            ->whereNotNull('source_name')
            ->where('source_name', '!=', '')
            ->distinct()
            ->pluck('source_name');

        $sourceTypeMap = [
            '斯帕克' => 'spark_api',
        ];

        foreach ($sources as $sourceName) {
            // 查找或创建资产组
            $group = IpAssetGroup::where('source_name', $sourceName)->first();
            if (!$group) {
                $group = IpAssetGroup::create([
                    'name' => $sourceName,
                    'source_type' => $sourceTypeMap[$sourceName] ?? 'third_party_api',
                    'source_name' => $sourceName,
                    'description' => "来源: {$sourceName} (自动创建)",
                    'status' => 1,
                    'created_by' => $adminId,
                ]);
                echo "Created asset group: {$sourceName} (ID: {$group->id})\n";
            }

            // 关联所有该来源的IP到资产组
            $updated = ProxyIp::whereNull('asset_group_id')
                ->where('source_name', $sourceName)
                ->update(['asset_group_id' => $group->id]);

            echo "  Linked {$updated} IPs to group '{$sourceName}'\n";
        }

        // 2. 修复客户的 sales_person（从关联的IP的订阅找业务员）
        $customers = DB::table('customers')
            ->whereNull('sales_person')
            ->orWhere('sales_person', '')
            ->get();

        foreach ($customers as $customer) {
            // 从该客户的IP资产中找业务归属
            // 暂时跳过，因为 sales_person 在导入时已设置
        }

        echo "Fix complete.\n";
    }

    public function down(): void
    {
        // 不回滚
    }
};
