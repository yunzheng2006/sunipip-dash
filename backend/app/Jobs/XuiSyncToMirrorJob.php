<?php

namespace App\Jobs;

use App\Models\XuiInbound;
use App\Services\Xui\XuiMirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 把一条主机上成功的 inbound 同步到备机
 *
 * 失败不影响主流程，只记录 mirror_sync_status=failed
 */
class XuiSyncToMirrorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [30, 90, 180];
    }

    public function __construct(
        public int $xuiInboundId,
        public string $action = 'sync', // sync | delete
    ) {}

    public function handle(XuiMirrorService $service): void
    {
        $record = XuiInbound::find($this->xuiInboundId);
        if (!$record) {
            Log::warning("XuiSyncToMirrorJob: record #{$this->xuiInboundId} not found");
            return;
        }

        if ($this->action === 'delete') {
            $service->syncDeleteToMirror($record);
        } else {
            $service->syncInboundToMirror($record);
        }
    }
}
