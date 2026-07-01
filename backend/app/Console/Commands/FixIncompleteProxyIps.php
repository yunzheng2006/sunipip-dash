<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

class FixIncompleteProxyIps extends Command
{
    protected $signature = 'proxy-ips:fix-incomplete';
    protected $description = '修复因上游维护导致凭证缺失的 ProxyIp 记录（从 Spark API 重新拉取）';

    public function handle(SparkApiService $spark): int
    {
        $incomplete = ProxyIp::whereNotNull('spark_instance_id')
            ->where(function ($q) {
                $q->whereNull('auth_username')
                  ->orWhereNull('auth_password')
                  ->orWhere('port', 0)
                  ->orWhereNull('port')
                  ->orWhere('ip_address', '');
            })
            ->whereNotIn('status', ['released', 'expired'])
            ->get();

        if ($incomplete->isEmpty()) {
            $this->info('没有需要修复的记录');
            return self::SUCCESS;
        }

        $this->info("找到 {$incomplete->count()} 条凭证不完整的 ProxyIp");

        $fixed = 0;
        foreach ($incomplete as $proxyIp) {
            $instanceId = $proxyIp->spark_instance_id;
            $this->line("处理: ProxyIp #{$proxyIp->id}, instance={$instanceId}");

            try {
                $data = $spark->getInstance(['instanceId' => $instanceId]);

                $ip   = $data['ip'] ?? null;
                $port = $data['port'] ?? null;
                $user = $data['username'] ?? null;
                $pass = $data['password'] ?? null;

                if (!$ip && !$port && !$user && !$pass) {
                    $this->warn("  Spark 返回数据仍为空，跳过");
                    continue;
                }

                $updates = [];
                if ($ip && !$proxyIp->ip_address) $updates['ip_address'] = $ip;
                if ($port && !$proxyIp->port)     $updates['port'] = $port;
                if ($user && !$proxyIp->auth_username) $updates['auth_username'] = $user;
                if ($pass && !$proxyIp->auth_password) $updates['auth_password'] = $pass;

                if (!empty($updates)) {
                    $finalIp   = $updates['ip_address'] ?? $proxyIp->ip_address;
                    $finalPort = $updates['port'] ?? $proxyIp->port;
                    $finalUser = $updates['auth_username'] ?? $proxyIp->auth_username;
                    $finalPass = $updates['auth_password'] ?? $proxyIp->auth_password;
                    $updates['socks5_info'] = implode(':', array_filter([$finalIp, $finalPort, $finalUser, $finalPass]));

                    $proxyIp->update($updates);
                    $this->info("  ✓ 已修复: {$updates['socks5_info']}");
                    $fixed++;
                } else {
                    $this->line("  无需更新");
                }

                // 同步更新 SparkInstance
                $sparkInst = SparkInstance::where('instance_id', $instanceId)->first();
                if ($sparkInst) {
                    $sparkInst->update(array_filter([
                        'ip'       => $ip,
                        'port'     => $port,
                        'username' => $user,
                        'password' => $pass,
                        'status'   => $data['status'] ?? null,
                        'expire_at' => isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : null,
                    ], fn($v) => $v !== null));
                }

                usleep(300000);
            } catch (\Throwable $e) {
                $this->error("  失败: {$e->getMessage()}");
            }
        }

        $this->info("修复完成：{$fixed}/{$incomplete->count()} 条");
        return self::SUCCESS;
    }
}
