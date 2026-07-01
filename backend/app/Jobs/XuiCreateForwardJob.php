<?php

namespace App\Jobs;

use App\Models\XuiInbound;
use App\Services\Xui\XuiApiException;
use App\Services\Xui\XuiForwardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 队列任务：处理单条 pending XuiInbound
 *
 * 依赖 XuiForwardService::processExistingRecord 完成实际工作。
 * 并发安全由 Cache::lock("xui:xray_settings:{panel_id}") 保证。
 *
 * 重试策略：3 次，退避 10s/30s/60s。
 */
class XuiCreateForwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public int $xuiInboundId) {}

    public function handle(XuiForwardService $service): void
    {
        $record = XuiInbound::with(['panel', 'proxyIp'])->find($this->xuiInboundId);
        if (!$record) {
            Log::warning("XuiCreateForwardJob: record #{$this->xuiInboundId} not found");
            return;
        }

        if (in_array($record->status, ['active', 'deleted'], true)) {
            return; // 幂等
        }

        if (!$record->panel || !$record->proxyIp) {
            $record->update([
                'status' => 'failed',
                'error_message' => '面板或源 IP 记录缺失',
            ]);
            return;
        }

        try {
            $service->processExistingRecord($record, $record->panel, $record->proxyIp);
        } catch (XuiApiException $e) {
            Log::warning("XuiCreateForwardJob #{$this->xuiInboundId} failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $record = XuiInbound::find($this->xuiInboundId);
        if ($record && in_array($record->status, ['pending', 'processing'], true)) {
            $record->update([
                'status' => 'failed',
                'error_message' => '任务最终失败: ' . substr($exception->getMessage(), 0, 400),
            ]);
        }
    }
}
