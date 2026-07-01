<?php

use App\Models\Customer;
use App\Models\IpAssetGroup;
use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 导入凯慕传媒 IP 数据（来自 4.12凯慕导入管理面板.xlsx，1188 条）
 *
 * 数据特征：
 *   - 单客户：凯慕传媒
 *   - 全部来自斯帕克
 *   - 到期时间为精确 datetime
 *   - 0 重复
 *
 * 策略：与之前导入脚本一致
 *   - 客户不存在则创建
 *   - IP (ip:port) 不存在则创建；存在则更新到期时间和归属
 *   - 订阅不存在则创建；存在则更新到期时间
 *   - 业务员不存在则创建
 *   - 资产组不存在则创建
 */
return new class extends Migration
{
    private array $countryMap = [
        '美国' => 'US', '美国-新泽西' => 'US', '美国-纽约' => 'US',
        '美国-洛杉矶' => 'US', '美国-洛杉矶-' => 'US',
        '美国-休斯敦' => 'US', '美国-维吉尼亚' => 'US',
        '巴西' => 'BR', '巴西-圣保罗' => 'BR',
        '墨西哥' => 'MX',
        '德国-法兰克福' => 'DE', '德国-法兰克福-' => 'DE', '德国-柏林' => 'DE',
        '英国' => 'GB',
        '法国-巴黎' => 'FR',
        '日本' => 'JP', '日本-东京' => 'JP',
    ];

    public function up(): void
    {
        $jsonPath = database_path('seeders/import_data_2026_04_12_kaimu.json');
        if (!file_exists($jsonPath)) {
            echo "import_data_2026_04_12_kaimu.json not found, skipping.\n";
            return;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        if (empty($rows)) {
            echo "No data.\n";
            return;
        }

        $adminId = User::where('username', 'admin')->value('id') ?? 1;

        $stats = [
            'rows' => count($rows),
            'new_customers' => 0,
            'new_staff' => 0,
            'new_asset_groups' => 0,
            'new_ips' => 0,
            'updated_ips' => 0,
            'new_subscriptions' => 0,
            'updated_subscriptions' => 0,
            'skipped' => 0,
        ];

        $customerCache = [];
        $staffCache = [];
        $assetGroupCache = [];

        foreach ($rows as $row) {
            [$customerName, $country, $socks5, $assetName, $expiresRaw, $salesPerson, $sourceName] = array_pad($row, 7, '');

            $socks5 = trim($socks5);
            if (empty($socks5)) {
                $stats['skipped']++;
                continue;
            }
            $parts = explode(':', $socks5);
            $ipAddress = trim($parts[0] ?? '');
            $port = (int) trim($parts[1] ?? '0');
            $authUser = trim($parts[2] ?? '');
            $authPass = trim($parts[3] ?? '');
            if (!$ipAddress || $port <= 0) {
                $stats['skipped']++;
                continue;
            }

            // 国家
            $countryClean = preg_replace('/[-\s].*$/', '', $country) ?: $country;
            $countryCode = $this->countryMap[$country] ?? ($this->countryMap[$countryClean] ?? 'XX');

            // 到期时间（已是 ISO 格式）
            $expiryDate = null;
            if ($expiresRaw) {
                try {
                    $expiryDate = \Carbon\Carbon::parse($expiresRaw);
                } catch (\Exception) {
                    // fallback
                }
            }

            // 业务员
            if ($salesPerson && !isset($staffCache[$salesPerson])) {
                $staff = User::where('name', $salesPerson)->first();
                if (!$staff) {
                    $staff = User::create([
                        'username' => 'staff_' . Str::random(6),
                        'password' => Hash::make('staff' . Str::random(8)),
                        'name' => $salesPerson,
                        'status' => 1,
                    ]);
                    $stats['new_staff']++;
                    try { $staff->assignRole('staff'); } catch (\Exception) {}
                }
                $staffCache[$salesPerson] = $staff;
            }

            // 客户
            $customerId = null;
            if ($customerName) {
                if (!isset($customerCache[$customerName])) {
                    $customer = Customer::where('customer_name', $customerName)->first();
                    if (!$customer) {
                        $customer = Customer::create([
                            'customer_name' => $customerName,
                            'username' => 'snp_' . Str::random(8),
                            'password' => Hash::make(Str::random(12)),
                            'sales_person' => $salesPerson ?: null,
                            'status' => 1,
                        ]);
                        $stats['new_customers']++;
                    } elseif ($salesPerson && empty($customer->sales_person)) {
                        $customer->update(['sales_person' => $salesPerson]);
                    }
                    $customerCache[$customerName] = $customer;
                }
                $customerId = $customerCache[$customerName]->id;
            }

            // 资产组
            $assetGroupId = null;
            if ($sourceName) {
                if (!isset($assetGroupCache[$sourceName])) {
                    $group = IpAssetGroup::where('source_name', $sourceName)->first();
                    if (!$group) {
                        $group = IpAssetGroup::create([
                            'name' => $sourceName,
                            'source_type' => $sourceName === '斯帕克' ? 'spark_api' : 'third_party_api',
                            'source_name' => $sourceName,
                            'description' => "来源: {$sourceName} (自动创建)",
                            'status' => 1,
                            'created_by' => $adminId,
                        ]);
                        $stats['new_asset_groups']++;
                    }
                    $assetGroupCache[$sourceName] = $group;
                }
                $assetGroupId = $assetGroupCache[$sourceName]->id;
            }

            // IP
            $existing = ProxyIp::where('ip_address', $ipAddress)->where('port', $port)->first();

            if ($existing) {
                $updates = [];
                if ($expiryDate && (!$existing->upstream_expires_at || !$existing->upstream_expires_at->equalTo($expiryDate))) {
                    $updates['upstream_expires_at'] = $expiryDate;
                }
                if ($customerId && $existing->assigned_customer_id !== $customerId) {
                    $updates['assigned_customer_id'] = $customerId;
                    $updates['status'] = 'assigned';
                }
                if ($assetGroupId && empty($existing->asset_group_id)) {
                    $updates['asset_group_id'] = $assetGroupId;
                }
                if ($assetName && $existing->asset_name !== $assetName) {
                    $updates['asset_name'] = $assetName;
                }
                if ($authUser && empty($existing->auth_username)) {
                    $updates['auth_username'] = $authUser;
                    $updates['auth_password'] = $authPass;
                    $updates['socks5_info'] = $socks5;
                }
                if ($country && (empty($existing->country_name) || $existing->country_name !== $countryClean)) {
                    $updates['country_name'] = $country; // 保留完整地区如"美国-新泽西"
                    $updates['country_code'] = $countryCode;
                }
                if (!empty($updates)) {
                    $existing->update($updates);
                    $stats['updated_ips']++;
                }
                $proxyIp = $existing->fresh();
            } else {
                $proxyIp = ProxyIp::create([
                    'asset_group_id' => $assetGroupId,
                    'socks5_info' => $socks5,
                    'ip_address' => $ipAddress,
                    'port' => $port,
                    'auth_username' => $authUser,
                    'auth_password' => $authPass,
                    'protocol' => 'socks5',
                    'asset_name' => $assetName ?: "{$country}-{$ipAddress}",
                    'country_code' => $countryCode,
                    'country_name' => $country, // 保留"美国-新泽西"这种完整标签
                    'ip_type' => 'residential',
                    'nature' => 'static',
                    'source_name' => $sourceName ?: '未知',
                    'status' => $customerId ? 'assigned' : 'available',
                    'assigned_customer_id' => $customerId,
                    'upstream_expires_at' => $expiryDate,
                ]);
                $stats['new_ips']++;
            }

            // 订阅
            if ($customerId && $expiryDate && $proxyIp) {
                $sub = Subscription::where('customer_id', $customerId)
                    ->where('proxy_ip_id', $proxyIp->id)
                    ->whereIn('status', ['active', 'expired'])
                    ->orderByDesc('id')
                    ->first();

                if ($sub) {
                    if (!$sub->expires_at || !$sub->expires_at->equalTo($expiryDate)) {
                        $sub->update([
                            'expires_at' => $expiryDate,
                            'status' => $expiryDate->gt(now()) ? 'active' : 'expired',
                        ]);
                        $stats['updated_subscriptions']++;
                    }
                } else {
                    Subscription::create([
                        'customer_id' => $customerId,
                        'proxy_ip_id' => $proxyIp->id,
                        'price' => 0,
                        'duration' => 1,
                        'unit' => 3,
                        'started_at' => now(),
                        'expires_at' => $expiryDate,
                        'status' => $expiryDate->gt(now()) ? 'active' : 'expired',
                        'created_by' => $adminId,
                        'remark' => '凯慕传媒导入 2026-04-12',
                    ]);
                    $stats['new_subscriptions']++;

                    IpAssignmentLog::create([
                        'proxy_ip_id' => $proxyIp->id,
                        'customer_id' => $customerId,
                        'action' => 'assign',
                        'operated_by' => $adminId,
                        'remark' => '凯慕传媒导入 2026-04-12',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        echo "凯慕传媒导入完成：\n";
        foreach ($stats as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
    }

    public function down(): void
    {
        Subscription::where('remark', '凯慕传媒导入 2026-04-12')->delete();
        IpAssignmentLog::where('remark', '凯慕传媒导入 2026-04-12')->delete();
    }
};
