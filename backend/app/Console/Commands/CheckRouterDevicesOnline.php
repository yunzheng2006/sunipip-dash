<?php

namespace App\Console\Commands;

use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckRouterDevicesOnline extends Command
{
    protected $signature = 'router:check-online';
    protected $description = '检测软路由设备在线状态，超过5分钟无心跳标记为offline并记录告警';

    public function handle(): int
    {
        $threshold = now()->subMinutes(5);

        // Find devices that are "online" but haven't sent heartbeat recently
        $staleDevices = RouterDevice::where('status', 'online')
            ->where(function ($q) use ($threshold) {
                $q->where('last_heartbeat_at', '<', $threshold)
                  ->orWhereNull('last_heartbeat_at');
            })
            ->get();

        if ($staleDevices->isEmpty()) {
            $this->info('所有在线设备心跳正常');
            return 0;
        }

        foreach ($staleDevices as $device) {
            $device->update(['status' => 'offline']);

            RouterEventLog::create([
                'router_device_id' => $device->id,
                'event_type' => 'offline',
                'severity' => 'warning',
                'message' => '设备超过5分钟无心跳，已标记为离线',
                'metadata' => [
                    'last_heartbeat_at' => $device->last_heartbeat_at?->toIso8601String(),
                    'customer_id' => $device->customer_id,
                ],
                'created_at' => now(),
            ]);

            Log::channel('daily')->warning("Router device #{$device->id} ({$device->serial_number}) went offline", [
                'device_id' => $device->id,
                'serial_number' => $device->serial_number,
                'customer_id' => $device->customer_id,
                'last_heartbeat_at' => $device->last_heartbeat_at,
            ]);
        }

        $this->warn("已标记 {$staleDevices->count()} 台设备为离线");
        return 0;
    }
}
