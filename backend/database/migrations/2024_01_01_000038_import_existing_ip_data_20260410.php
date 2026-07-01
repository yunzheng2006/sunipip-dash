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
 * 导入增量客户 IP 数据（来自 副本4.10全部.xlsx，884 条）
 *
 * 策略（按用户要求）：
 *   - 新客户：直接创建
 *   - 新 IP 资产：直接创建
 *   - 重复 IP：如果到期时间变了（续费），覆盖 expires_at + 最新归属；其他字段保持
 *   - 业务员/资产组缺失则自动补建
 *
 * 导入源 JSON 格式：[[客户名, 地区, socks5, 资产名, 到期, 业务员, IP归属], ...]
 */
return new class extends Migration
{
    private array $countryMap = [
        '美国' => 'US', '美国-洛杉矶' => 'US',
        '巴西' => 'BR', '墨西哥' => 'MX',
        '德国' => 'DE',
        '英国' => 'GB', '英国-伦敦' => 'GB',
        '泰国' => 'TH', '印尼' => 'ID', '菲律宾' => 'PH',
        '俄罗斯' => 'RU', '日本' => 'JP',
        '马来西亚' => 'MY', '越南' => 'VN',
        '新加坡' => 'SG', '澳大利亚' => 'AU',
        '意大利' => 'IT', '法国' => 'FR', '西班牙' => 'ES',
        '香港' => 'HK', '台湾' => 'TW',
        '南非' => 'ZA',
        '沙特' => 'SA', '迪拜' => 'AE', '阿联酋' => 'AE',
        '肯尼亚' => 'KE', '阿尔及利亚' => 'DZ',
    ];

    public function up(): void
    {
        $jsonPath = database_path('seeders/import_data_2026_04_10.json');
        if (!file_exists($jsonPath)) {
            echo "import_data_2026_04_10.json not found, skipping.\n";
            return;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        if (empty($rows)) {
            echo "No data to import.\n";
            return;
        }

        $customerCache = [];
        $staffCache = [];
        $assetGroupCache = [];

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

        $adminUser = User::where('username', 'admin')->first();
        $adminId = $adminUser?->id ?? 1;

        foreach ($rows as $row) {
            [$customerName, $country, $socks5, $assetName, $expiresRaw, $salesPerson, $sourceName] = array_pad($row, 7, '');

            // 解析 socks5 → ip/port/user/pass
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
            if (empty($ipAddress) || $port <= 0) {
                $stats['skipped']++;
                continue;
            }

            // 解析国家
            $countryCode = $this->countryMap[$country] ?? 'XX';
            $countryClean = preg_replace('/[-\s].+/', '', $country);
            $expiryDate = $this->parseExpiry($expiresRaw);

            // 业务员（users 表）
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
                    if (class_exists(\Spatie\Permission\Models\Role::class)) {
                        try { $staff->assignRole('staff'); } catch (\Exception) {}
                    }
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
                    } else if ($salesPerson && empty($customer->sales_person)) {
                        // 补齐业务归属
                        $customer->update(['sales_person' => $salesPerson]);
                    }
                    $customerCache[$customerName] = $customer;
                }
                $customerId = $customerCache[$customerName]->id;
            }

            // 资产组（按 source_name 分组）
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

            // 是否已存在同一 IP:port
            $existing = ProxyIp::where('ip_address', $ipAddress)->where('port', $port)->first();

            if ($existing) {
                // 更新：到期时间、归属客户、业务归属、资产组
                $updates = [];
                if ($expiryDate && (!$existing->upstream_expires_at
                    || !$existing->upstream_expires_at->equalTo($expiryDate))) {
                    $updates['upstream_expires_at'] = $expiryDate;
                }
                if ($customerId && $existing->assigned_customer_id !== $customerId) {
                    $updates['assigned_customer_id'] = $customerId;
                    $updates['status'] = 'assigned';
                }
                if ($assetGroupId && empty($existing->asset_group_id)) {
                    $updates['asset_group_id'] = $assetGroupId;
                }
                if ($sourceName && empty($existing->source_name)) {
                    $updates['source_name'] = $sourceName;
                }
                if ($authUser && empty($existing->auth_username)) {
                    $updates['auth_username'] = $authUser;
                    $updates['auth_password'] = $authPass;
                    $updates['socks5_info'] = $socks5;
                }
                if ($assetName && $existing->asset_name !== $assetName) {
                    $updates['asset_name'] = $assetName;
                }

                if (!empty($updates)) {
                    $existing->update($updates);
                    $stats['updated_ips']++;
                }

                $proxyIp = $existing->fresh();
            } else {
                // 新增
                $proxyIp = ProxyIp::create([
                    'asset_group_id' => $assetGroupId,
                    'socks5_info' => $socks5,
                    'ip_address' => $ipAddress,
                    'port' => $port,
                    'auth_username' => $authUser,
                    'auth_password' => $authPass,
                    'protocol' => 'socks5',
                    'asset_name' => $assetName ?: "{$countryClean}-{$ipAddress}",
                    'country_code' => $countryCode,
                    'country_name' => $countryClean,
                    'ip_type' => 'residential',
                    'nature' => 'static',
                    'source_name' => $sourceName ?: '未知',
                    'status' => $customerId ? 'assigned' : 'available',
                    'assigned_customer_id' => $customerId,
                    'upstream_expires_at' => $expiryDate,
                ]);
                $stats['new_ips']++;
            }

            // 订阅：如果有客户 + 到期日
            if ($customerId && $expiryDate && $proxyIp) {
                $sub = Subscription::where('customer_id', $customerId)
                    ->where('proxy_ip_id', $proxyIp->id)
                    ->whereIn('status', ['active', 'expired'])
                    ->orderByDesc('id')
                    ->first();

                if ($sub) {
                    // 更新到期时间（续费的情况）
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
                        'remark' => '历史数据导入 2026-04-10',
                    ]);
                    $stats['new_subscriptions']++;

                    IpAssignmentLog::create([
                        'proxy_ip_id' => $proxyIp->id,
                        'customer_id' => $customerId,
                        'action' => 'assign',
                        'operated_by' => $adminId,
                        'remark' => '历史数据导入 2026-04-10',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        echo "Import 2026-04-10 complete:\n";
        foreach ($stats as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
    }

    public function down(): void
    {
        // 只删除本次批次的订阅与日志（按 remark 标识），不碰 IP 资产和客户
        Subscription::where('remark', '历史数据导入 2026-04-10')->delete();
        IpAssignmentLog::where('remark', '历史数据导入 2026-04-10')->delete();
    }

    /**
     * 解析 "4.27到期" / "04-27" 等格式为 Carbon 日期。
     * 默认使用当前年份，如果解析结果已经过了很久（早于今天 6 个月前），尝试下一年。
     */
    private function parseExpiry(string $value): ?\Carbon\Carbon
    {
        $value = trim($value);
        if (empty($value)) return null;

        if (preg_match('/(\d{1,2})[.\-\/](\d{1,2})/', $value, $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }
            $year = (int) now()->year;
            try {
                $date = \Carbon\Carbon::create($year, $month, $day);
            } catch (\Exception) {
                return null;
            }
            // 如果已经比今天早了 3 个月以上，视为下一年（明显的跨年续费）
            if ($date && $date->lt(now()->subMonths(3))) {
                $date->addYear();
            }
            return $date;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
};
