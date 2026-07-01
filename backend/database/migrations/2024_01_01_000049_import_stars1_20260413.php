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
 * 导入 太阳IP-stars1.xlsx（60 条）
 *
 * 客户：stars / 地区：巴西 / 来源：云登 / 业务：陈小同
 * 资产名格式：{地区}-{IP}（如 巴西-200.234.167.111）
 * 列：客户名称, 地区, socks5, 到期时间, 业务归属, IP归属
 */
return new class extends Migration
{
    private array $countryMap = [
        '巴西' => 'BR',
    ];

    public function up(): void
    {
        $jsonPath = database_path('seeders/import_data_2026_04_13_stars1.json');
        if (!file_exists($jsonPath)) {
            echo "JSON not found, skipping.\n";
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
            'new_customers' => 0, 'new_staff' => 0, 'new_asset_groups' => 0,
            'new_ips' => 0, 'updated_ips' => 0,
            'new_subscriptions' => 0, 'updated_subscriptions' => 0, 'skipped' => 0,
        ];

        $customerCache = [];
        $staffCache = [];
        $assetGroupCache = [];

        foreach ($rows as $row) {
            // 列顺序：客户名称, 地区, socks5, 到期时间, 业务归属, IP归属
            [$customerName, $country, $socks5, $expiresRaw, $salesPerson, $sourceName] = array_pad($row, 6, '');

            $socks5 = trim($socks5);
            if (empty($socks5)) { $stats['skipped']++; continue; }
            $parts = explode(':', $socks5);
            $ipAddress = trim($parts[0] ?? '');
            $port = (int) trim($parts[1] ?? '0');
            $authUser = trim($parts[2] ?? '');
            $authPass = trim($parts[3] ?? '');
            if (!$ipAddress || $port <= 0) { $stats['skipped']++; continue; }

            $countryCode = $this->countryMap[$country] ?? 'XX';
            // 资产名 = 地区-IP
            $assetName = "{$country}-{$ipAddress}";

            $expiryDate = null;
            if ($expiresRaw) {
                try { $expiryDate = \Carbon\Carbon::parse($expiresRaw); } catch (\Exception) {}
            }

            // 业务员
            if ($salesPerson && !isset($staffCache[$salesPerson])) {
                $staff = User::where('name', $salesPerson)->first();
                if (!$staff) {
                    $staff = User::create([
                        'username' => 'staff_' . Str::random(6),
                        'password' => Hash::make('staff' . Str::random(8)),
                        'name' => $salesPerson, 'status' => 1,
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
                            'source_type' => 'third_party_api',
                            'source_name' => $sourceName,
                            'description' => "来源: {$sourceName} (自动创建)",
                            'status' => 1, 'created_by' => $adminId,
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
                if ($existing->asset_name !== $assetName) {
                    $updates['asset_name'] = $assetName;
                }
                if ($authUser && empty($existing->auth_username)) {
                    $updates['auth_username'] = $authUser;
                    $updates['auth_password'] = $authPass;
                    $updates['socks5_info'] = $socks5;
                }
                if (!empty($updates)) { $existing->update($updates); $stats['updated_ips']++; }
                $proxyIp = $existing->fresh();
            } else {
                $proxyIp = ProxyIp::create([
                    'asset_group_id' => $assetGroupId,
                    'socks5_info' => $socks5,
                    'ip_address' => $ipAddress, 'port' => $port,
                    'auth_username' => $authUser, 'auth_password' => $authPass,
                    'protocol' => 'socks5',
                    'asset_name' => $assetName,
                    'country_code' => $countryCode, 'country_name' => $country,
                    'ip_type' => 'residential', 'nature' => 'static',
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
                    ->orderByDesc('id')->first();

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
                        'customer_id' => $customerId, 'proxy_ip_id' => $proxyIp->id,
                        'price' => 0, 'duration' => 1, 'unit' => 3,
                        'started_at' => now(), 'expires_at' => $expiryDate,
                        'status' => $expiryDate->gt(now()) ? 'active' : 'expired',
                        'created_by' => $adminId,
                        'remark' => 'stars1导入 2026-04-13',
                    ]);
                    $stats['new_subscriptions']++;

                    IpAssignmentLog::create([
                        'proxy_ip_id' => $proxyIp->id, 'customer_id' => $customerId,
                        'action' => 'assign', 'operated_by' => $adminId,
                        'remark' => 'stars1导入 2026-04-13', 'created_at' => now(),
                    ]);
                }
            }
        }

        echo "stars1 导入完成：\n";
        foreach ($stats as $k => $v) echo "  {$k}: {$v}\n";
    }

    public function down(): void
    {
        Subscription::where('remark', 'stars1导入 2026-04-13')->delete();
        IpAssignmentLog::where('remark', 'stars1导入 2026-04-13')->delete();
    }
};
