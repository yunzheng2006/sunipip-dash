<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\ProxyIp;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 客户自助面板 - 我的 IP 资产
 */
class ProxyIpController extends Controller
{
    /**
     * GET /customer/ips
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $query = ProxyIp::with([
                'activeSubscription.forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'activeSubscription.forwardRule.forwardPlan:id,name,module,display_host',
            ])
            ->where('assigned_customer_id', $customer->id)
            ->where('status', '!=', 'released');

        if ($request->filled('country')) {
            $c = $request->input('country');
            $query->where(function ($q) use ($c) {
                $q->where('country_code', strtoupper($c))
                  ->orWhere('country_name', 'like', "%{$c}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('keyword')) {
            $kw = $request->input('keyword');
            $query->where(function ($q) use ($kw) {
                $q->where('asset_name', 'like', "%{$kw}%")
                  ->orWhere('ip_address', 'like', "%{$kw}%")
                  ->orWhereHas('activeSubscription', function ($sq) use ($kw) {
                      $sq->where('customer_remark', 'like', "%{$kw}%");
                  });
            });
        }
        if ($request->filled('group_id')) {
            $groupId = (int) $request->input('group_id');
            $query->whereIn('proxy_ips.id', function ($sq) use ($groupId, $customer) {
                $sq->select('proxy_ip_id')
                   ->from('customer_ip_group_items')
                   ->join('customer_ip_groups', 'customer_ip_groups.id', '=', 'customer_ip_group_items.group_id')
                   ->where('customer_ip_groups.id', $groupId)
                   ->where('customer_ip_groups.customer_id', $customer->id);
            });
        }
        if ($request->filled('product_type')) {
            $pt = $request->input('product_type');
            if ($pt === 'static') {
                $query->whereHas('activeSubscription', function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('purchased_module')
                           ->orWhere('purchased_module', 'static');
                    });
                });
            } else {
                $query->whereHas('activeSubscription', function ($q) use ($pt) {
                    $q->where('purchased_module', $pt);
                });
            }
        }

        $sort = $request->input('sort', '');
        $subStartedAt = Subscription::select('started_at')
            ->whereColumn('proxy_ip_id', 'proxy_ips.id')
            ->orderByDesc('started_at')
            ->limit(1);
        switch ($sort) {
            case 'expires_asc':
                $query->orderBy('upstream_expires_at', 'asc'); break;
            case 'expires_desc':
                $query->orderBy('upstream_expires_at', 'desc'); break;
            case 'created_asc':
                $query->orderBy($subStartedAt)->orderBy('id', 'asc'); break;
            case 'country':
                $query->orderBy('country_name')->orderBy('id'); break;
            case 'asset_name':
                $query->orderBy('asset_name')->orderBy('id'); break;
            case 'created_desc':
            default:
                $query->orderByDesc($subStartedAt)->orderByDesc('id');
        }
        $paginated = $query->paginate(min((int) $request->input('per_page', 20), 100));

        // 检查是否有开通中的 Spark 订单（status=1，尚未返回 IP）
        $pendingOrders = \App\Models\SparkOrder::where('status', 1)
            ->whereJsonContains('request_data->customer_id', $customer->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $data = $this->paginated($paginated)->getData(true);
        $data['data']['pending_orders'] = $pendingOrders;
        return response()->json($data);
    }

    /**
     * GET /customer/ips/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $ip = ProxyIp::with([
                'activeSubscription.forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'activeSubscription.forwardRule.forwardPlan:id,name,module,display_host',
            ])
            ->where('assigned_customer_id', $customer->id)
            ->findOrFail($id);

        return $this->success($ip);
    }

    /**
     * GET /customer/ips/export
     * 导出 TXT，格式 ip:port:user:pass 每行一条
     */
    public function export(Request $request): StreamedResponse
    {
        $customer = $request->user();
        $format = $request->input('format', 'socks5');

        $query = ProxyIp::with([
                'activeSubscription.forwardRule.deviceGroup:id,original_connect_host,custom_connect_host',
                'activeSubscription.forwardRule.forwardPlan:id,display_host',
            ])
            ->where('assigned_customer_id', $customer->id)
            ->where('status', 'assigned');

        $this->applySortParam($query, $request->input('sort', 'id'));

        $ips = $query->get();

        // 对每个 IP 解析对客展示字段（优先转发地址）
        $rows = $ips->map(function ($ip) {
            $fwd = $ip->activeSubscription?->forwardRule;
            if ($fwd && $fwd->status === 'active' && $fwd->listen_port) {
                $host = $fwd->forwardPlan?->display_host
                    ?: $fwd->deviceGroup?->custom_connect_host
                    ?: $fwd->deviceGroup?->original_connect_host
                    ?: $ip->ip_address;
                $port = $fwd->listen_port;
            } else {
                $host = $ip->ip_address;
                $port = $ip->port;
            }
            return [
                'asset_name' => $ip->asset_name,
                'host' => $host,
                'port' => $port,
                'user' => $ip->auth_username,
                'pass' => $ip->auth_password,
                'country_name' => $ip->country_name,
                'expires_at' => ($ip->activeSubscription?->expires_at ?? $ip->upstream_expires_at)?->format('Y-m-d'),
            ];
        });

        $filename = 'my_ips_' . now()->format('Ymd_His') . '.' . ($format === 'csv' ? 'csv' : 'txt');

        return response()->streamDownload(function () use ($rows, $format) {
            $out = fopen('php://output', 'w');
            if ($format === 'csv') {
                fputcsv($out, ['资产名', 'Host', '端口', '用户名', '密码', '地区', '到期时间']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['asset_name'], $r['host'], $r['port'], $r['user'], $r['pass'], $r['country_name'], $r['expires_at']]);
                }
            } else {
                foreach ($rows as $r) {
                    fwrite($out, implode(':', array_filter([
                        $r['host'], $r['port'], $r['user'], $r['pass'],
                    ])) . "\n");
                }
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => $format === 'csv' ? 'text/csv' : 'text/plain',
        ]);
    }

    /**
     * GET /customer/ips/export-qr
     * 导出成可扫描表格（.xlsx）：每行一条 IP + V2Ray socks:// URL + 嵌入二维码
     *
     * 严格对齐 socks5_collect_and_build_batch.py 输出格式
     */
    public function exportQr(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $customer = $request->user();

        $query = ProxyIp::with([
                'activeSubscription.forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'activeSubscription.forwardRule.forwardPlan:id,display_host',
            ])
            ->where('assigned_customer_id', $customer->id)
            ->where('status', 'assigned');

        // 支持导出选中的 IP（逗号分隔的 id 列表）
        if ($request->filled('ids')) {
            $ids = array_filter(array_map('intval', explode(',', $request->input('ids'))));
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        $this->applySortParam($query, $request->input('sort', 'id'));
        $ips = $query->get();

        if ($ips->isEmpty()) {
            abort(404, '暂无可导出的 IP');
        }

        @set_time_limit(180);
        @ini_set('memory_limit', '512M');

        $service = app(\App\Services\Export\QrXlsxExportService::class);
        $tempPath = $service->generate($ips);

        $filename = now()->format('Ymd_His') . '_最终结果.xlsx';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * 通用排序：id=导入顺序 / country=按国家 / asset_name=按名称 / expires=按到期
     */
    private function applySortParam($query, string $sort): void
    {
        $allowed = [
            'country' => 'country_name',
            'asset_name' => 'asset_name',
            'expires' => 'upstream_expires_at',
        ];
        $col = $allowed[$sort] ?? 'id';
        $query->orderBy($col)->orderBy('id');
    }
}
