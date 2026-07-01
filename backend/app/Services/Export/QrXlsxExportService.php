<?php

namespace App\Services\Export;

use App\Models\ProxyIp;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 导出可扫描 socks5 表格
 *
 * 严格对齐 socks5_collect_and_build_batch.py 的输出格式：
 *   表头: socks5原链接 | socks5已转发 | socks5新链接 | 备注 | 二维码
 *   列宽: 42 / 46 / 78 / 28 / 16
 *   行高: 24 (header) / 78 (data)
 *   header: 深蓝 #1F4E78 + 白字加粗
 *   QR:   96×96 PNG 嵌入 E 列
 *   freeze panes: A2
 *   autofilter: A1:E{last}
 *
 * socks5新链接格式（V2Ray / Shadowrocket 兼容）：
 *   socks://{url_encode(base64(user:pass))}@{host}:{listen_port}#{url_encode(remark)}
 */
class QrXlsxExportService
{
    /**
     * @param Collection<int, ProxyIp> $ips 已预加载 activeSubscription.forwardRule.deviceGroup
     * @return string 生成的临时 xlsx 文件路径
     */
    public function generate(Collection $ips): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('最终结果');

        // 表头
        $headers = ['socks5原链接', 'socks5已转发', 'socks5新链接', '备注', '二维码', '开通时间', '到期时间'];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue([$col + 1, 1], $label);
        }

        // 表头样式
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1F4E78'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // 列宽
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(46);
        $sheet->getColumnDimension('C')->setWidth(78);
        $sheet->getColumnDimension('D')->setWidth(28);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(20);

        // 表头行高
        $sheet->getRowDimension(1)->setRowHeight(24);

        // 数据行
        $rowIdx = 2;
        foreach ($ips as $ip) {
            $row = $this->buildRow($ip);
            if (!$row) {
                continue;
            }

            $sheet->setCellValue("A{$rowIdx}", $row['raw']);
            $sheet->setCellValue("B{$rowIdx}", $row['forwarded']);
            $sheet->setCellValueExplicit(
                "C{$rowIdx}",
                $row['v2ray_url'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $sheet->setCellValue("D{$rowIdx}", $row['remark']);
            $sheet->setCellValue("F{$rowIdx}", $row['started_at']);
            $sheet->setCellValue("G{$rowIdx}", $row['expires_at']);

            // 数据列样式
            $sheet->getStyle("A{$rowIdx}:G{$rowIdx}")->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            // 生成 QR 二维码并嵌入 E 列
            $this->embedQrCode($sheet, $row['v2ray_url'], "E{$rowIdx}", $rowIdx);

            $sheet->getRowDimension($rowIdx)->setRowHeight(78);
            $rowIdx++;
        }

        $lastRow = $rowIdx - 1;

        // 底部细边框
        if ($lastRow >= 1) {
            $sheet->getStyle("A1:G{$lastRow}")->applyFromArray([
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FFD9E2F3'],
                    ],
                ],
            ]);
        }

        // 冻结首行
        $sheet->freezePane('A2');

        // 自动筛选
        if ($lastRow >= 1) {
            $sheet->setAutoFilter("A1:G{$lastRow}");
        }

        // 写入临时文件
        $tempPath = tempnam(sys_get_temp_dir(), 'qrxlsx_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * 构造一行数据
     *
     * @return array{raw: string, forwarded: string, v2ray_url: string, remark: string}|null
     */
    private function buildRow(ProxyIp $ip): ?array
    {
        $user = $ip->auth_username ?? '';
        $pass = $ip->auth_password ?? '';
        if (!$ip->ip_address || !$ip->port || !$user || !$pass) {
            return null;
        }

        // socks5原链接：原始 Spark IP
        $raw = "{$ip->ip_address}:{$ip->port}:{$user}:{$pass}";

        // 转发后的 host:port
        $sub = $ip->activeSubscription;
        $forwardRule = $sub?->forwardRule;

        if ($forwardRule && $forwardRule->status === 'active' && $forwardRule->listen_port) {
            $host = $forwardRule->forwardPlan?->display_host
                ?: $forwardRule->deviceGroup?->custom_connect_host
                ?: $forwardRule->deviceGroup?->original_connect_host
                ?: $ip->ip_address;
            $listenPort = (int) $forwardRule->listen_port;
        } else {
            // 没有转发就用原 IP 端口
            $host = $ip->ip_address;
            $listenPort = (int) $ip->port;
        }

        $remark = $ip->asset_name ?: "{$ip->country_name}-{$ip->ip_address}";

        // socks5已转发: host:port:user:pass
        $forwarded = "{$host}:{$listenPort}:{$user}:{$pass}";

        // socks5新链接: v2ray/shadowrocket 格式
        // socks://{url_encode(base64(user:pass))}@{host}:{port}#{url_encode(remark)}
        $authB64 = base64_encode("{$user}:{$pass}");
        $v2rayUrl = 'socks://' . rawurlencode($authB64)
            . "@{$host}:{$listenPort}"
            . '#' . rawurlencode($remark);

        return [
            'raw' => $raw,
            'forwarded' => $forwarded,
            'v2ray_url' => $v2rayUrl,
            'remark' => $remark,
            'started_at' => $sub?->started_at?->format('Y-m-d') ?? 'null',
            'expires_at' => $sub?->expires_at?->format('Y-m-d') ?? ($ip->upstream_expires_at?->format('Y-m-d') ?? 'null'),
        ];
    }

    /**
     * 生成二维码并嵌入到指定单元格
     */
    private function embedQrCode($sheet, string $text, string $cellCoord, int $rowIdx): void
    {
        try {
            // endroid/qr-code v6 API：直接 new QrCode + PngWriter->write()
            $qr = new QrCode(
                data: $text,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 200,
                margin: 8,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
            );
            $writer = new PngWriter();
            $result = $writer->write($qr);
            $imageString = $result->getString();

            // 使用 MemoryDrawing 避免写临时文件
            $gdImage = imagecreatefromstring($imageString);
            if ($gdImage === false) {
                return;
            }

            $drawing = new MemoryDrawing();
            $drawing->setName('QR' . $rowIdx);
            $drawing->setDescription('QR Code');
            $drawing->setImageResource($gdImage);
            $drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);
            $drawing->setMimeType(MemoryDrawing::MIMETYPE_DEFAULT);
            $drawing->setCoordinates($cellCoord);
            $drawing->setWidth(96);
            $drawing->setHeight(96);
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        } catch (\Throwable $e) {
            \Log::warning("QR embed failed at {$cellCoord}: " . $e->getMessage());
        }
    }
}
