<?php

use App\Models\Customer;
use App\Models\IpAssetGroup;
use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 修复 2026-04-10 批次导入的到期时间 + 补建订阅
 *
 * 原因：上一版迁移的 parseExpiry 只能处理 "4.27到期" 一类格式，
 * 导致带年份 / 带时间戳 / 带备注（客户3.31到期/ip实际4.24到期）的行
 * 都解析失败 → 无 expires_at → 无订阅创建。
 *
 * 新的解析规则：
 *   - "2026-04-15 14:56:02"  → Carbon::parse
 *   - "2027.1.6到期"          → 2027-01-06
 *   - "2026.11.22到期"        → 2026-11-22
 *   - "4.27到期" / "4.27到"   → 默认 2026
 *   - "客户X到期/ipY到期"     → **以 ip 的日期为准**（用户要求）
 *   - 纯日期 "4-15" / "4/15"  → 默认 2026
 *
 * 策略：重新跑一次 JSON → 对每行算出正确 expires_at →
 *   - 如果 ProxyIp 的 upstream_expires_at 不对（或为空），更新
 *   - 如果没有对应 Subscription，补建
 *   - 如果已有 Subscription 但日期不对，更新
 */
return new class extends Migration
{
    public function up(): void
    {
        $jsonPath = database_path('seeders/import_data_2026_04_10.json');
        if (!file_exists($jsonPath)) {
            echo "JSON 不存在，跳过。\n";
            return;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        $adminId = User::where('username', 'admin')->value('id') ?? 1;

        $stats = [
            'rows' => count($rows),
            'parsed_ok' => 0,
            'parse_failed' => 0,
            'ip_updated_expiry' => 0,
            'ip_not_found' => 0,
            'sub_created' => 0,
            'sub_updated' => 0,
            'sub_skipped_no_customer' => 0,
        ];

        // 逐行处理：按 (ip, port) 找 ProxyIp；按 (customer, proxy_ip) 找/建 Subscription
        $customerCache = [];

        foreach ($rows as $row) {
            [$customerName, , $socks5, , $expiresRaw, , ] = array_pad($row, 7, '');

            $socks5 = trim($socks5);
            $parts = explode(':', $socks5);
            $ipAddress = trim($parts[0] ?? '');
            $port = (int) trim($parts[1] ?? '0');
            if (!$ipAddress || $port <= 0) {
                continue;
            }

            $expiryDate = $this->parseExpiryEnhanced($expiresRaw);
            if (!$expiryDate) {
                $stats['parse_failed']++;
                echo "  [解析失败] {$ipAddress}:{$port}  原始='{$expiresRaw}'\n";
                continue;
            }
            $stats['parsed_ok']++;

            // 1. 更新 ProxyIp 的 upstream_expires_at
            $proxyIp = ProxyIp::where('ip_address', $ipAddress)
                ->where('port', $port)
                ->first();
            if (!$proxyIp) {
                $stats['ip_not_found']++;
                continue;
            }

            if (!$proxyIp->upstream_expires_at
                || !$proxyIp->upstream_expires_at->equalTo($expiryDate)) {
                $proxyIp->update(['upstream_expires_at' => $expiryDate]);
                $stats['ip_updated_expiry']++;
            }

            // 2. 找客户
            $customerId = null;
            if ($customerName) {
                if (!isset($customerCache[$customerName])) {
                    $customerCache[$customerName] = Customer::where('customer_name', $customerName)->first();
                }
                $customerId = $customerCache[$customerName]?->id;
            }
            // 如果 ProxyIp 已有 assigned_customer_id 但 JSON 没客户名，也用回 DB 值
            $customerId = $customerId ?: $proxyIp->assigned_customer_id;

            if (!$customerId) {
                $stats['sub_skipped_no_customer']++;
                continue;
            }

            // 3. 查/建 Subscription
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
                    $stats['sub_updated']++;
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
                    'remark' => '到期时间修复 2026-04-11',
                ]);
                $stats['sub_created']++;

                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $customerId,
                    'action' => 'assign',
                    'operated_by' => $adminId,
                    'remark' => '到期时间修复 2026-04-11',
                    'created_at' => now(),
                ]);
            }
        }

        echo "\n到期时间修复完成：\n";
        foreach ($stats as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
    }

    public function down(): void
    {
        Subscription::where('remark', '到期时间修复 2026-04-11')->delete();
        IpAssignmentLog::where('remark', '到期时间修复 2026-04-11')->delete();
    }

    /**
     * 增强版到期时间解析
     *
     * 支持格式：
     *   - "2026-04-15 14:56:02"   → 完整时间戳
     *   - "2027.1.6到期"           → 带年份的点分
     *   - "2026.11.22到期"
     *   - "4.27到期" / "4.27到"    → 默认 2026
     *   - "客户3.31到期/ip实际4.24到期" → 取 ip 那部分（4.24）
     *   - "客户5.3到期/ip4.28到期"     → 取 ip 那部分（4.28）
     *
     * 规则：
     *   - 带完整年份 (4 位) → 直接用
     *   - 带标注"ip" → 以 ip 后面的日期为准
     *   - 不带年份 → 默认 2026（按用户要求）
     */
    private function parseExpiryEnhanced(string $raw): ?\Carbon\Carbon
    {
        $raw = trim($raw);
        if (empty($raw)) return null;

        // 1. 标准时间戳：2026-04-15 14:56:02 / 2026/4/15
        if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $raw)) {
            try {
                return \Carbon\Carbon::parse($raw);
            } catch (\Exception) {
                // fall through
            }
        }

        // 2. 带标注："客户X到期/ip实际Y到期" → 提取 ip 后面的日期
        if (preg_match('/ip[^0-9]*(\d{1,2})[\.\-\/](\d{1,2})/u', $raw, $m)) {
            return $this->buildDate(null, (int) $m[1], (int) $m[2]);
        }

        // 3. 带 4 位年份："2027.1.6到期" / "2026.11.22到期"
        if (preg_match('/^(\d{4})[\.\-\/](\d{1,2})[\.\-\/](\d{1,2})/', $raw, $m)) {
            return $this->buildDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 4. 带 2 位年份："25.4.27" → 2025
        if (preg_match('/^(\d{2})[\.\-\/](\d{1,2})[\.\-\/](\d{1,2})/', $raw, $m)) {
            $year = 2000 + (int) $m[1];
            return $this->buildDate($year, (int) $m[2], (int) $m[3]);
        }

        // 5. 月日格式："4.27到期" / "4.27到" / "4-27"
        if (preg_match('/(\d{1,2})[\.\-\/](\d{1,2})/', $raw, $m)) {
            return $this->buildDate(null, (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * 构造日期，year=null 时默认 2026
     */
    private function buildDate(?int $year, int $month, int $day): ?\Carbon\Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        // 用户要求：不带年份的就是 2026 年
        $year = $year ?: 2026;
        try {
            return \Carbon\Carbon::create($year, $month, $day, 23, 59, 59);
        } catch (\Exception) {
            return null;
        }
    }
};
