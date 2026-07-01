<?php

use App\Models\Customer;
use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 导入现有客户IP数据（来自 副本4.9散.xlsx，610条记录）
 */
return new class extends Migration
{
    private array $countryMap = [
        '美国' => 'US', '美国-洛杉矶' => 'US', '巴西' => 'BR', '墨西哥' => 'MX',
        '德国' => 'DE', '英国' => 'GB', '英国-伦敦' => 'GB', '泰国' => 'TH',
        '印尼' => 'ID', '菲律宾' => 'PH', '俄罗斯' => 'RU', '日本' => 'JP',
        '马来西亚' => 'MY', '越南' => 'VN', '新加坡' => 'SG', '澳大利亚' => 'AU',
        '意大利' => 'IT', '法国' => 'FR', '西班牙' => 'ES', '香港' => 'HK',
        '台湾' => 'TW', '南非' => 'ZA', '沙特' => 'SA', '迪拜' => 'AE',
        '阿联酋' => 'AE', '肯尼亚' => 'KE', '阿尔及利亚' => 'DZ',
    ];

    public function up(): void
    {
        $jsonPath = database_path('seeders/import_data.json');
        if (!file_exists($jsonPath)) {
            echo "import_data.json not found, skipping.\n";
            return;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        if (empty($rows)) {
            echo "No data to import.\n";
            return;
        }

        // 缓存已处理的客户和业务员，避免重复查询
        $customerCache = [];
        $staffCache = [];
        $imported = 0;
        $skipped = 0;

        // 确保有一个默认管理员作为 created_by
        $adminUser = User::where('username', 'admin')->first();
        $adminId = $adminUser?->id ?? 1;

        foreach ($rows as $row) {
            [$customerName, $country, $socks5, $assetName, $expiresAt, $salesPerson, $sourceName] = $row;

            if (empty($socks5)) {
                $skipped++;
                continue;
            }

            // 解析 socks5
            $socks5 = trim($socks5);
            $parts = explode(':', $socks5);
            $ipAddress = trim($parts[0] ?? '');
            $port = (int) trim($parts[1] ?? '0');
            $authUser = trim($parts[2] ?? '');
            $authPass = trim($parts[3] ?? '');

            if (empty($ipAddress) || $port <= 0) {
                $skipped++;
                continue;
            }

            // 跳过重复IP
            if (ProxyIp::where('ip_address', $ipAddress)->where('port', $port)->exists()) {
                $skipped++;
                continue;
            }

            // 解析国家代码
            $countryCode = $this->countryMap[$country] ?? 'XX';
            // 清理国家名中的城市信息
            $countryClean = preg_replace('/[-\s].+/', '', $country);

            // 解析到期时间
            $expiryDate = $this->parseExpiry($expiresAt);

            // 创建/获取业务员（User表，staff角色）
            if ($salesPerson && !isset($staffCache[$salesPerson])) {
                $staff = User::where('name', $salesPerson)->first();
                if (!$staff) {
                    $staff = User::create([
                        'username' => 'staff_' . Str::random(6),
                        'password' => Hash::make('staff123456'),
                        'name' => $salesPerson,
                        'status' => 1,
                    ]);
                    if (class_exists(\Spatie\Permission\Models\Role::class)) {
                        try { $staff->assignRole('staff'); } catch (\Exception $e) {}
                    }
                }
                $staffCache[$salesPerson] = $staff;
            }

            // 创建/获取客户
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
                    }
                    $customerCache[$customerName] = $customer;
                }
                $customerId = $customerCache[$customerName]->id;
            }

            // 创建 IP 资产
            $proxyIp = ProxyIp::create([
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

            // 如果有客户，创建订阅
            if ($customerId && $expiryDate) {
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
                    'remark' => '历史数据导入',
                ]);

                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $customerId,
                    'action' => 'assign',
                    'operated_by' => $adminId,
                    'remark' => '历史数据导入',
                    'created_at' => now(),
                ]);
            }

            $imported++;
        }

        echo "Import complete: {$imported} imported, {$skipped} skipped.\n";
    }

    public function down(): void
    {
        // 删除导入的数据（通过 remark 标识）
        $subIds = Subscription::where('remark', '历史数据导入')->pluck('id');
        Subscription::whereIn('id', $subIds)->delete();
        IpAssignmentLog::where('remark', '历史数据导入')->delete();
        // IP资产不删除，避免误删
    }

    private function parseExpiry(string $value): ?\Carbon\Carbon
    {
        $value = trim($value);
        if (empty($value)) return null;

        // "4.27到期" → 2026-04-27
        if (preg_match('/^(\d{1,2})\.(\d{1,2})/', $value, $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            // 默认2026年；如果月份比当前早且已过期，可能是今年的
            $date = \Carbon\Carbon::create(2026, $month, $day);
            return $date;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
};
