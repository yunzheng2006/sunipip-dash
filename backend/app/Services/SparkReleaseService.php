<?php

namespace App\Services;

use App\Models\ProxyIp;
use App\Models\SparkOrder;
use Illuminate\Support\Facades\Log;

/**
 * Spark 实例释放编排器
 *
 * 职责：
 *   1. 调用 Spark DelProxy API
 *   2. 记录 SparkOrder 审计流水
 *   3. 更新 ProxyIp.spark_release_status (pending/confirmed/failed) 和相关字段
 *   4. 返回结构化结果供上层决定 UI 展示
 *
 * 重要：调用方应在事务之外调用本方法，因为网络 IO 不应持有数据库事务。
 */
class SparkReleaseService
{
    /**
     * 释放单个 Spark 实例
     *
     * @return array{status: string, message: string, req_order_no: ?string, spark_order_id: ?int}
     */
    public static function releaseInstance(ProxyIp $proxyIp, string $reason = ''): array
    {
        if (!$proxyIp->spark_instance_id) {
            return [
                'status' => 'skipped',
                'message' => '该 IP 不是 Spark 实例',
                'req_order_no' => null,
                'spark_order_id' => null,
            ];
        }

        $spark = app(SparkApiService::class);
        $reqOrderNo = SparkOrder::generateReqOrderNo();

        try {
            $result = $spark->delProxy($reqOrderNo, [$proxyIp->spark_instance_id]);

            // Spark 响应结构：status 1=开通中/处理中 2=完成 3=失败
            $sparkStatus = (int) ($result['status'] ?? 1);

            $sparkOrder = SparkOrder::create([
                'req_order_no' => $reqOrderNo,
                'spark_order_no' => $result['orderNo'] ?? null,
                'method' => 'DelProxy',
                'product_id' => '',
                'amount' => 1,
                'duration' => 0,
                'unit' => 0,
                'status' => $sparkStatus,
                'request_data' => [
                    'proxy_ip_id' => $proxyIp->id,
                    'instance_id' => $proxyIp->spark_instance_id,
                    'reason' => $reason,
                ],
                'response_data' => $result,
            ]);

            // 根据 Spark 返回的 status 决定本地状态
            $localStatus = match ($sparkStatus) {
                2 => 'confirmed', // Spark 已立即确认
                3 => 'failed',    // Spark 明确返回失败
                default => 'pending', // 1 或其他 = 等待 Spark 异步处理 + 回调
            };

            $proxyIp->update([
                'spark_release_status' => $localStatus,
                'spark_release_order_no' => $reqOrderNo,
                'spark_released_at' => $localStatus === 'confirmed' ? now() : null,
                'spark_release_error' => $localStatus === 'failed' ? ($result['msg'] ?? 'Spark 返回 status=3') : null,
            ]);

            return [
                'status' => $localStatus,
                'message' => match ($localStatus) {
                    'confirmed' => 'Spark 已确认释放',
                    'pending' => 'Spark 已受理，等待异步确认',
                    'failed' => $result['msg'] ?? 'Spark 返回失败',
                },
                'req_order_no' => $reqOrderNo,
                'spark_order_id' => $sparkOrder->id,
            ];
        } catch (\Throwable $e) {
            Log::error("SparkReleaseService: DelProxy failed", [
                'proxy_ip_id' => $proxyIp->id,
                'instance_id' => $proxyIp->spark_instance_id,
                'error' => $e->getMessage(),
            ]);

            // 记录失败状态，运维可手动重试
            $proxyIp->update([
                'spark_release_status' => 'failed',
                'spark_release_order_no' => $reqOrderNo,
                'spark_released_at' => null,
                'spark_release_error' => substr($e->getMessage(), 0, 500),
            ]);

            // 记录失败的 SparkOrder 作为审计
            try {
                SparkOrder::create([
                    'req_order_no' => $reqOrderNo,
                    'method' => 'DelProxy',
                    'product_id' => '',
                    'amount' => 1,
                    'duration' => 0,
                    'unit' => 0,
                    'status' => 3,
                    'request_data' => [
                        'proxy_ip_id' => $proxyIp->id,
                        'instance_id' => $proxyIp->spark_instance_id,
                        'reason' => $reason,
                    ],
                    'response_data' => ['error' => $e->getMessage()],
                ]);
            } catch (\Throwable) {
                // ignore
            }

            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'req_order_no' => $reqOrderNo,
                'spark_order_id' => null,
            ];
        }
    }

    /**
     * 主动校验：调用 Spark GetInstance 查询实例当前状态，更新本地 spark_release_status
     *
     * @return array{status: string, instance_status: ?int, message: string}
     */
    public static function verifyReleaseStatus(ProxyIp $proxyIp): array
    {
        if (!$proxyIp->spark_instance_id) {
            return ['status' => 'skipped', 'instance_status' => null, 'message' => '非 Spark 实例'];
        }

        try {
            $spark = app(SparkApiService::class);
            $data = $spark->getInstance(['instanceId' => $proxyIp->spark_instance_id]);

            // Spark instance status: 1=开通中 2=正常 3=释放中 4=释放完成
            $sparkInstanceStatus = (int) ($data['status'] ?? 0);

            $localStatus = match ($sparkInstanceStatus) {
                4 => 'confirmed',
                3 => 'pending',
                2, 1 => 'failed',  // 仍然活跃 → 释放没生效
                default => $proxyIp->spark_release_status ?? 'pending',
            };

            $updates = ['spark_release_status' => $localStatus];
            if ($localStatus === 'confirmed' && !$proxyIp->spark_released_at) {
                $updates['spark_released_at'] = now();
                $updates['spark_release_error'] = null;
            }
            if ($localStatus === 'failed') {
                $updates['spark_release_error'] = "查询到 Spark 实例仍处于 status={$sparkInstanceStatus}（未释放）";
            }

            $proxyIp->update($updates);

            return [
                'status' => $localStatus,
                'instance_status' => $sparkInstanceStatus,
                'message' => match ($localStatus) {
                    'confirmed' => 'Spark 已确认释放（status=4）',
                    'pending' => 'Spark 释放中（status=3），请稍后再试',
                    'failed' => "❌ Spark 实例仍活跃（status={$sparkInstanceStatus}），实际未释放",
                    default => '未知状态',
                },
            ];
        } catch (\Throwable $e) {
            // Spark API 不到 → 实例可能已经被清理（释放完成后 API 返回 not found）
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'not found') || str_contains($msg, 'does not exist')) {
                $proxyIp->update([
                    'spark_release_status' => 'confirmed',
                    'spark_released_at' => $proxyIp->spark_released_at ?: now(),
                    'spark_release_error' => null,
                ]);
                return [
                    'status' => 'confirmed',
                    'instance_status' => null,
                    'message' => 'Spark 已确认释放（实例已从平台移除）',
                ];
            }

            return [
                'status' => $proxyIp->spark_release_status ?? 'unknown',
                'instance_status' => null,
                'message' => '查询失败: ' . $e->getMessage(),
            ];
        }
    }
}
