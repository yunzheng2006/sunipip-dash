<?php

namespace App\Jobs;

use App\Models\FeishuSyncConfig;
use App\Services\Feishu\FeishuBitableService;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 队列任务：为单条飞书记录上传二维码
 *
 * 参数轻量（只传 record_id + socks URL 字符串），QR 图片在 worker 侧生成。
 */
class FeishuUploadQrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(
        public int $configId,
        public string $recordId,
        public string $socksUrl,
    ) {
        // 独立队列，避免阻塞 default 里的转发/扣费等关键任务
        $this->onQueue('feishu');
    }

    public function handle(): void
    {
        $config = FeishuSyncConfig::find($this->configId);
        if (!$config || !$config->is_active) return;

        try {
            // 生成二维码
            $qr = new QrCode(
                data: $this->socksUrl,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 300,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
            );
            $pngData = (new PngWriter())->write($qr)->getString();

            // 上传
            $api = new FeishuBitableService($config);
            $fileToken = $api->uploadMedia("qr_{$this->recordId}.png", $pngData);
            if (!$fileToken) return;

            $qrFieldName = $config->effectiveMapping()['qr_image'] ?? '直连二维码';
            $api->batchUpdate([[
                'record_id' => $this->recordId,
                'fields' => [$qrFieldName => [['file_token' => $fileToken]]],
            ]]);
        } catch (\Throwable $e) {
            Log::warning("FeishuUploadQrJob failed: record={$this->recordId} error={$e->getMessage()}");
            throw $e;
        }
    }
}
