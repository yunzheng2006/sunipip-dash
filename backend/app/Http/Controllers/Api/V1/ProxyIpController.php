<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IpAssignmentLog;
use App\Models\IpImportLog;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProxyIpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QueryBuilder::for(ProxyIp::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('country_code'),
                AllowedFilter::exact('source_name'),
                AllowedFilter::exact('asset_group_id'),
                AllowedFilter::exact('ip_group_id'),
                AllowedFilter::exact('assigned_customer_id'),
                AllowedFilter::exact('nature'),
                AllowedFilter::exact('ip_type'),
                AllowedFilter::partial('asset_name'),
                AllowedFilter::partial('ip_address'),
                AllowedFilter::partial('country_name'),
                AllowedFilter::callback('customer_name', function ($q, $value) {
                    $q->whereHas('assignedCustomer', fn($c) => $c->where('customer_name', 'like', "%{$value}%"));
                }),
                AllowedFilter::exact('is_test_pool'),
                AllowedFilter::callback('date_from', fn($q, $v) => $q->where('created_at', '>=', $v)),
                AllowedFilter::callback('date_to', fn($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59')),
                AllowedFilter::callback('expires_from', fn($q, $v) => $q->where('upstream_expires_at', '>=', $v)),
                AllowedFilter::callback('expires_to', fn($q, $v) => $q->where('upstream_expires_at', '<=', $v . ' 23:59:59')),
            ])
            ->with([
                'assetGroup:id,name,source_name',
                'ipGroup:id,name',
                'assignedCustomer:id,customer_name,sales_person',
                'activeSubscription.forwardRule.deviceGroup:id,name,custom_connect_host,original_connect_host',
            ])
            ->allowedSorts(['id', 'ip_address', 'status', 'country_code', 'country_name', 'created_at'])
            ->defaultSort('-id');

        // 默认不显示已释放的IP（除非显式筛选）
        if (!$request->filled('filter.status') || $request->input('filter.status') !== 'released') {
            if (!$request->boolean('include_released')) {
                $query->where('status', '!=', 'released');
            }
        }

        // 数据隔离
        $user = $request->user();
        if ($user && !$user->can('customer.view_all')) {
            $customerIds = Customer::where('sales_person', $user->name)->pluck('id');
            $query->where(function ($q) use ($customerIds) {
                $q->whereIn('assigned_customer_id', $customerIds)
                  ->orWhere('status', 'available');
            });
        }

        $ips = $query->paginate($request->input('per_page', 15));

        return $this->paginated($ips);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_group_id'       => 'nullable|integer|exists:ip_asset_groups,id',
            'socks5_info'          => 'nullable|string|max:500',
            'ip_address'           => 'nullable|string|max:100',
            'port'                 => 'nullable|integer',
            'auth_username'        => 'nullable|string|max:100',
            'auth_password'        => 'nullable|string|max:100',
            'protocol'             => 'nullable|string|max:20',
            'asset_name'           => 'nullable|string|max:100',
            'country_code'         => 'required|string|max:10',
            'country_name'         => 'nullable|string|max:100',
            'city'                 => 'nullable|string|max:100',
            'ip_type'              => 'required|string|in:residential,datacenter,mobile',
            'nature'               => 'required|string|in:static,rotating',
            'net_type'             => 'nullable|string|max:50',
            'source_name'          => 'nullable|string|max:100',
            'sales_cost'           => 'nullable|numeric|min:0',
            'status'               => 'nullable|string|in:available,assigned,offline,expired',
            'remark'               => 'nullable|string|max:1000',
            'upstream_expires_at'  => 'nullable|date',
        ]);

        if (!empty($data['socks5_info'])) {
            $parsed = ProxyIp::parseSocks5Info($data['socks5_info']);
            $data = array_merge($parsed, $data);
        }

        $data['status'] = $data['status'] ?? 'available';

        $proxyIp = ProxyIp::create($data);

        return $this->success($proxyIp, 'IP创建成功');
    }

    /**
     * 批量添加 IP（文本粘贴，一行一个）
     * POST /proxy-ips/batch
     *
     * 两种模式：
     *   mode=single_region (默认): ip:port:user:pass，国家代码在表单统一指定
     *   mode=multi_region:         ip:port:user:pass:country_code，每行自带国家
     */
    public function batchStore(Request $request): JsonResponse
    {
        $mode = $request->input('mode', 'single_region');

        $rules = [
            'lines'            => 'required|string',
            'mode'             => 'nullable|string|in:single_region,multi_region',
            'asset_group_id'   => 'required|integer|exists:ip_asset_groups,id',
            'ip_group_id'      => 'nullable|integer|exists:ip_groups,id',
            'source_name'      => 'nullable|string|max:100',
            'ip_type'          => 'nullable|string|in:residential,datacenter,mobile',
            'nature'           => 'nullable|string|in:static,rotating',
            'upstream_expires_at' => 'nullable|date',
            'remark'           => 'nullable|string|max:1000',
            'sales_cost'       => 'nullable|numeric|min:0',
        ];

        if ($mode === 'single_region') {
            $rules['country_code'] = 'required|string|max:10';
            $rules['country_name'] = 'nullable|string|max:100';
        }

        $data = $request->validate($rules);

        $lines = array_filter(array_map('trim', explode("\n", $data['lines'])));
        if (empty($lines)) {
            return $this->error('没有有效的 IP 行', 422);
        }
        if (count($lines) > 500) {
            return $this->error('单次最多 500 条', 422);
        }

        $assetGroup = \App\Models\IpAssetGroup::find($data['asset_group_id']);

        // 国家代码→中文名映射
        $codeToName = [
            'BR' => '巴西', 'MX' => '墨西哥', 'US' => '美国', 'ID' => '印尼',
            'TH' => '泰国', 'DE' => '德国', 'JP' => '日本', 'KR' => '韩国',
            'IN' => '印度', 'GB' => '英国', 'FR' => '法国', 'CA' => '加拿大',
            'AU' => '澳大利亚', 'SG' => '新加坡', 'MY' => '马来西亚', 'VN' => '越南',
            'PH' => '菲律宾', 'RU' => '俄罗斯', 'TR' => '土耳其', 'KH' => '柬埔寨',
            'HK' => '香港', 'TW' => '台湾', 'NG' => '尼日利亚', 'ZA' => '南非',
            'AR' => '阿根廷', 'CL' => '智利', 'CO' => '哥伦比亚', 'PE' => '秘鲁',
            'EG' => '埃及', 'SA' => '沙特', 'AE' => '阿联酋', 'PK' => '巴基斯坦',
            'BD' => '孟加拉', 'NL' => '荷兰', 'ES' => '西班牙', 'IT' => '意大利',
            'PL' => '波兰', 'SE' => '瑞典', 'CH' => '瑞士', 'PT' => '葡萄牙',
        ];

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 1;
            $parts = explode(':', $line);

            if (count($parts) < 2) {
                $errors[] = "第{$lineNum}行格式错误（至少需要 ip:port）";
                continue;
            }

            $ip = $parts[0];
            $port = (int) ($parts[1] ?? 0);

            $isValidHost = filter_var($ip, FILTER_VALIDATE_IP)
                || (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $ip) && strlen($ip) <= 253);
            if (!$isValidHost || $port < 1 || $port > 65535) {
                $errors[] = "第{$lineNum}行 IP/域名 或端口无效: {$ip}:{$port}";
                continue;
            }

            $username = $parts[2] ?? null;
            $password = $parts[3] ?? null;

            // 解析国家代码
            if ($mode === 'multi_region') {
                $lineCountryCode = strtoupper(trim($parts[4] ?? ''));
                if (empty($lineCountryCode)) {
                    $errors[] = "第{$lineNum}行缺少国家代码（第5段）";
                    continue;
                }
                $countryCode = $lineCountryCode;
                $countryName = $codeToName[$countryCode] ?? $countryCode;
            } else {
                $countryCode = $data['country_code'];
                $countryName = $data['country_name'] ?? $codeToName[strtoupper($countryCode)] ?? $assetGroup?->country_name ?? '';
            }

            // 重复检测（含软删除记录）：同地址+端口但账号/密码不同的是独立资产，只有四元组全同才算重复
            $existingIp = ProxyIp::withTrashed()
                ->where('ip_address', $ip)
                ->where('port', $port)
                ->whereRaw("COALESCE(auth_username, '') = ?", [$username ?? ''])
                ->whereRaw("COALESCE(auth_password, '') = ?", [$password ?? ''])
                ->first();
            if ($existingIp) {
                if ($existingIp->trashed()) {
                    \App\Models\Subscription::where('proxy_ip_id', $existingIp->id)->delete();
                    \App\Models\IpAssignmentLog::where('proxy_ip_id', $existingIp->id)->delete();
                    \App\Models\ForwardRule::where('proxy_ip_id', $existingIp->id)->delete();
                    $existingIp->forceDelete();
                } else {
                    // 有活跃订阅的 IP 不允许覆盖
                    $hasActive = \App\Models\Subscription::where('proxy_ip_id', $existingIp->id)
                        ->where('status', 'active')->exists();
                    if ($hasActive) {
                        $skipped++;
                        continue;
                    }
                    // 无活跃订阅：重复导入同一资产，刷新信息并重置为可用
                    $existingIp->update([
                        'asset_group_id'      => $data['asset_group_id'],
                        'ip_group_id'         => $data['ip_group_id'] ?? null,
                        'socks5_info'         => implode(':', array_filter([$ip, $port, $username, $password])),
                        'auth_username'       => $username,
                        'auth_password'       => $password,
                        'asset_name'          => "{$countryName}-{$ip}",
                        'country_code'        => $countryCode,
                        'country_name'        => $countryName,
                        'source_name'         => $data['source_name'] ?? $assetGroup?->source_name ?? '手动添加',
                        'sales_cost'          => $data['sales_cost'] ?? null,
                        'status'              => 'available',
                        'assigned_customer_id' => null,
                        'upstream_expires_at' => $data['upstream_expires_at'] ?? null,
                        'remark'              => $data['remark'] ?? null,
                        'released_at'         => null,
                        'release_reason'      => null,
                    ]);
                    $created++;
                    continue;
                }
            }

            $socks5Info = implode(':', array_filter([$ip, $port, $username, $password]));

            ProxyIp::create([
                'asset_group_id'      => $data['asset_group_id'],
                'ip_group_id'         => $data['ip_group_id'] ?? null,
                'socks5_info'         => $socks5Info,
                'ip_address'          => $ip,
                'port'                => $port,
                'auth_username'       => $username,
                'auth_password'       => $password,
                'protocol'            => 'socks5',
                'asset_name'          => "{$countryName}-{$ip}",
                'country_code'        => $countryCode,
                'country_name'        => $countryName,
                'ip_type'             => $data['ip_type'] ?? 'residential',
                'nature'              => $data['nature'] ?? 'static',
                'source_name'         => $data['source_name'] ?? $assetGroup?->source_name ?? '手动添加',
                'sales_cost'          => $data['sales_cost'] ?? null,
                'status'              => 'available',
                'upstream_expires_at' => $data['upstream_expires_at'] ?? null,
                'remark'              => $data['remark'] ?? null,
            ]);
            $created++;
        }

        return $this->success([
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
            'total'   => count($lines),
        ], "成功添加 {$created} 条" . ($skipped ? "，跳过 {$skipped} 条重复" : ''));
    }

    public function show(ProxyIp $proxyIp): JsonResponse
    {
        $proxyIp->load([
            'assetGroup:id,name,source_type,source_name',
            'ipGroup:id,name,isp_type,net_type',
            'assignedCustomer:id,customer_name,username,sales_person',
            'activeSubscription.forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
            'activeSubscription.forwardRule.panel:id,name',
            'subscriptions' => fn($q) => $q->with('customer:id,customer_name')->latest()->limit(10),
            'assignmentLogs' => fn($q) => $q->with(['customer:id,customer_name', 'operator:id,name'])->latest('created_at')->limit(20),
        ]);

        return $this->success($proxyIp);
    }

    public function update(Request $request, ProxyIp $proxyIp): JsonResponse
    {
        $data = $request->validate([
            'asset_group_id'       => 'nullable|integer|exists:ip_asset_groups,id',
            'socks5_info'          => 'nullable|string|max:500',
            'ip_address'           => 'nullable|string|max:100',
            'port'                 => 'nullable|integer',
            'auth_username'        => 'nullable|string|max:100',
            'auth_password'        => 'nullable|string|max:100',
            'protocol'             => 'nullable|string|max:20',
            'asset_name'           => 'nullable|string|max:100',
            'country_code'         => 'sometimes|string|max:10',
            'country_name'         => 'nullable|string|max:100',
            'city'                 => 'nullable|string|max:100',
            'ip_type'              => 'sometimes|string|in:residential,datacenter,mobile',
            'nature'               => 'sometimes|string|in:static,rotating',
            'net_type'             => 'nullable|string|max:50',
            'source_name'          => 'nullable|string|max:100',
            'sales_cost'           => 'nullable|numeric|min:0',
            'status'               => 'nullable|string|in:available,assigned,offline,expired',
            'remark'               => 'nullable|string|max:1000',
            'upstream_expires_at'  => 'nullable|date',
        ]);

        if (!empty($data['socks5_info'])) {
            $parsed = ProxyIp::parseSocks5Info($data['socks5_info']);
            $data = array_merge($parsed, $data);
        }

        $proxyIp->update($data);

        return $this->success($proxyIp, 'IP更新成功');
    }

    public function destroy(ProxyIp $proxyIp): JsonResponse
    {
        if ($proxyIp->status !== 'available') {
            return $this->error('只能删除状态为可用的IP', 422);
        }

        $proxyIp->delete();

        return $this->success(null, 'IP已删除');
    }

    public function assign(Request $request, ProxyIp $proxyIp): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'price'       => 'required|numeric|min:0',
            'duration'    => 'required|integer|min:1',
            'unit'        => 'required|integer|in:1,2,3,4',
        ]);

        try {
        $result = DB::transaction(function () use ($proxyIp, $data, $request) {
            $proxyIp = ProxyIp::lockForUpdate()->find($proxyIp->id);
            if (!$proxyIp || $proxyIp->status !== 'available') {
                throw new \Exception('该IP当前不可分配');
            }

            $price = (float) $data['price'];
            $customerId = (int) $data['customer_id'];
            $shouldDeduct = $price > 0;

            if ($shouldDeduct) {
                $customer = Customer::lockForUpdate()->findOrFail($customerId);
                if ((float) $customer->balance < $price) {
                    throw new \Exception(sprintf('客户余额不足：当前 ¥%.2f，需要 ¥%.2f', $customer->balance, $price));
                }
                $balanceBefore = (float) $customer->balance;
                $customer->decrement('balance', $price);
                $balanceAfter = round($balanceBefore - $price, 2);
            }

            $expiresAt = \App\Support\DurationHelper::addToDate(now(), (int) $data['duration'], (int) $data['unit']);

            if ($proxyIp->upstream_expires_at && $proxyIp->upstream_expires_at->isFuture()) {
                $expiresAt = $proxyIp->upstream_expires_at;
            }

            $ipSalesCost = $proxyIp->sales_cost;
            $subscription = Subscription::create([
                'customer_id'  => $customerId,
                'proxy_ip_id'  => $proxyIp->id,
                'price'        => $price,
                'sales_cost'   => $ipSalesCost,
                'hard_cost'    => $ipSalesCost,
                'duration'     => $data['duration'],
                'unit'         => $data['unit'],
                'started_at'   => now(),
                'expires_at'   => $expiresAt,
                'status'       => 'active',
                'renewed_count' => 0,
                'created_by'   => $request->user()?->id,
                'balance_deducted' => $shouldDeduct,
            ]);

            if ($shouldDeduct) {
                Transaction::create([
                    'customer_id'   => $customerId,
                    'type'          => Transaction::TYPE_PURCHASE,
                    'amount'        => -$price,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'related_type'  => Subscription::class,
                    'related_id'    => $subscription->id,
                    'description'   => "分配IP({$proxyIp->ip_address})，价格¥{$price}",
                    'operated_by'   => $request->user()?->id,
                ]);
            }

            $proxyIp->update([
                'status'               => 'assigned',
                'assigned_customer_id' => $customerId,
            ]);

            IpAssignmentLog::create([
                'proxy_ip_id'    => $proxyIp->id,
                'customer_id'    => $customerId,
                'subscription_id' => $subscription->id,
                'action'         => 'assign',
                'operated_by'    => $request->user()?->id,
                'remark'         => "分配IP给客户，价格{$data['price']}，时长{$data['duration']}",
                'created_at'     => now(),
            ]);

            return $subscription;
        });
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($result, 'IP分配成功');
    }

    public function unassign(Request $request, ProxyIp $proxyIp): JsonResponse
    {
        if ($proxyIp->status !== 'assigned') {
            return $this->error('该IP当前未分配', 422);
        }

        DB::transaction(function () use ($proxyIp, $request) {
            $customerId = $proxyIp->assigned_customer_id;

            $activeSubscription = $proxyIp->activeSubscription;
            if ($activeSubscription) {
                $activeSubscription->update(['status' => 'cancelled']);
            }

            $proxyIp->update([
                'status'               => 'available',
                'assigned_customer_id' => null,
            ]);

            IpAssignmentLog::create([
                'proxy_ip_id'    => $proxyIp->id,
                'customer_id'    => $customerId,
                'subscription_id' => $activeSubscription?->id,
                'action'         => 'unassign',
                'operated_by'    => $request->user()?->id,
                'remark'         => '取消分配IP',
                'created_at'     => now(),
            ]);
        });

        return $this->success(null, 'IP已取消分配');
    }

    /**
     * 释放IP资产
     * POST /proxy-ips/{id}/release
     * - 设置 status=released，记录释放原因
     * - 保留历史记录
     * - 如果是 Spark IP，调用 DelProxy 自动释放
     */
    public function release(Request $request, ProxyIp $proxyIp): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
            'auto_release_spark' => 'nullable|boolean', // Spark IP 是否同时调用 DelProxy
        ]);

        if ($proxyIp->status === 'released') {
            return $this->error('该IP已释放', 422);
        }

        $autoReleaseSpark = $data['auto_release_spark'] ?? true;

        DB::transaction(function () use ($proxyIp, $request, $data, $autoReleaseSpark) {
            $userId = $request->user()->id;
            $customerId = $proxyIp->assigned_customer_id;

            // 取消关联的活跃订阅
            $activeSub = $proxyIp->activeSubscription;
            if ($activeSub) {
                $activeSub->update(['status' => 'cancelled']);
            }

            $updates = [
                'status' => 'released',
                'assigned_customer_id' => null,
                'released_at' => now(),
                'release_reason' => $data['reason'] ?? '手动释放',
                'released_by' => $userId,
            ];

            if ($autoReleaseSpark && $proxyIp->spark_instance_id) {
                $updates['spark_release_status'] = 'pending';
            }

            $proxyIp->update($updates);

            IpAssignmentLog::create([
                'proxy_ip_id' => $proxyIp->id,
                'customer_id' => $customerId,
                'subscription_id' => $activeSub?->id,
                'action' => 'release',
                'operated_by' => $userId,
                'remark' => '释放IP: ' . ($data['reason'] ?? '手动释放'),
                'created_at' => now(),
            ]);
        });

        // 事务外：删除 NY/3x-ui 转发规则
        $activeSub = $proxyIp->activeSubscription ?? \App\Models\Subscription::where('proxy_ip_id', $proxyIp->id)
            ->orderByDesc('id')->first();
        if ($activeSub) {
            try {
                app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($activeSub);
            } catch (\Throwable $e) {
                \Log::warning("Release IP: delete NY forward failed: {$e->getMessage()}");
            }
            try {
                app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($activeSub);
            } catch (\Throwable $e) {
                \Log::warning("Release IP: delete XUI forward failed: {$e->getMessage()}");
            }
        }

        // Spark API 调用放在事务外
        $sparkResult = null;
        if ($autoReleaseSpark && $proxyIp->fresh()->spark_instance_id) {
            $sparkResult = \App\Services\SparkReleaseService::releaseInstance($proxyIp->fresh(), 'manual_release');
        }

        $msg = '已释放';
        if ($sparkResult) {
            $msg .= $sparkResult['status'] === 'failed'
                ? '。⚠️ Spark 释放失败: ' . $sparkResult['message'] . '（IP 仍会从可用池移除，请到详情页手动核验）'
                : '。Spark: ' . $sparkResult['message'];
        }

        return $this->success([
            'spark_release' => $sparkResult,
        ], $msg);
    }

    /**
     * 主动核验 Spark 实例是否已真正释放
     * POST /proxy-ips/{proxy_ip}/verify-spark-release
     */
    public function verifySparkRelease(ProxyIp $proxyIp): JsonResponse
    {
        if (!$proxyIp->spark_instance_id) {
            return $this->error('该 IP 不是 Spark 实例', 422);
        }

        $result = \App\Services\SparkReleaseService::verifyReleaseStatus($proxyIp);

        return $this->success([
            'proxy_ip' => $proxyIp->fresh(),
            'verify' => $result,
        ], $result['message']);
    }

    /**
     * 手动重试 Spark 释放（用于 spark_release_status=failed 的记录）
     * POST /proxy-ips/{proxy_ip}/retry-spark-release
     */
    public function retrySparkRelease(ProxyIp $proxyIp): JsonResponse
    {
        if (!$proxyIp->spark_instance_id) {
            return $this->error('该 IP 不是 Spark 实例', 422);
        }

        $result = \App\Services\SparkReleaseService::releaseInstance($proxyIp, 'manual_retry');

        return $this->success([
            'proxy_ip' => $proxyIp->fresh(),
            'spark_release' => $result,
        ], $result['message']);
    }

    public function stats(): JsonResponse
    {
        $byStatus = ProxyIp::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byCountry = ProxyIp::selectRaw('country_code, count(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code');

        return $this->success([
            'by_status'  => $byStatus,
            'by_country' => $byCountry,
        ]);
    }

    /**
     * 批量导入IP
     * POST /proxy-ips/import
     * CSV列: socks5, asset_name, country, customer_name, expires_at, sales_person, source_name, remark
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'asset_group_id' => 'required|exists:ip_asset_groups,id',
            'ip_group_id' => 'nullable|exists:ip_groups,id',
        ]);

        $file = $request->file('file');
        $assetGroupId = $request->input('asset_group_id');
        $ipGroupId = $request->input('ip_group_id');
        $userId = $request->user()->id;

        // 读取 CSV，自动处理编码（Excel 导出的 CSV 常为 GBK/GB18030）
        $rawContent = file_get_contents($file->getPathname());

        // 去 BOM（UTF-8 BOM = EF BB BF, UTF-16 LE BOM = FF FE）
        $rawContent = preg_replace('/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $rawContent);

        // 编码检测和转换
        if (!mb_check_encoding($rawContent, 'UTF-8')) {
            $detected = mb_detect_encoding($rawContent, ['UTF-8', 'GBK', 'GB2312', 'GB18030', 'BIG5', 'ASCII'], true);
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $detected ?: 'GBK');
        }

        // 写入临时文件供 fgetcsv 读取（确保正确处理换行符）
        $tmpPath = tempnam(sys_get_temp_dir(), 'csv_utf8_');
        file_put_contents($tmpPath, $rawContent);

        // 设置 auto_detect_line_endings 以兼容 Mac 的 \r 换行
        $prevAutoDetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');

        $handle = fopen($tmpPath, 'r');
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            @unlink($tmpPath);
            ini_set('auto_detect_line_endings', $prevAutoDetect);
            return $this->error('CSV文件为空或格式错误');
        }

        $header = array_map('trim', $header);

        \Illuminate\Support\Facades\Log::info('CSV import: header parsed', [
            'header' => $header,
            'encoding_detected' => mb_detect_encoding($rawContent, ['UTF-8', 'GBK', 'ASCII'], true),
            'content_length' => strlen($rawContent),
        ]);

        // 创建导入记录
        $importLog = IpImportLog::create([
            'asset_group_id' => $assetGroupId,
            'source_type' => 'csv',
            'file_name' => $file->getClientOriginalName(),
            'total_count' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'status' => 'processing',
            'imported_by' => $userId,
        ]);

        $total = 0;
        $success = 0;
        $failed = 0;
        $errors = [];

        // 国家名→代码映射（简易）
        $countryMap = [
            '巴西' => 'BR', '墨西哥' => 'MX', '美国' => 'US', '印尼' => 'ID',
            '泰国' => 'TH', '德国' => 'DE', '日本' => 'JP', '韩国' => 'KR',
            '印度' => 'IN', '英国' => 'GB', '法国' => 'FR', '加拿大' => 'CA',
            '澳大利亚' => 'AU', '新加坡' => 'SG', '马来西亚' => 'MY', '越南' => 'VN',
            '菲律宾' => 'PH', '俄罗斯' => 'RU', '土耳其' => 'TR', '柬埔寨' => 'KH',
        ];

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            if (count($row) < 3) {
                $failed++;
                $errors[] = ['row' => $total + 1, 'message' => '列数不足'];
                continue;
            }

            $data = [];
            foreach ($header as $i => $col) {
                $data[trim($col)] = trim($row[$i] ?? '');
            }

            $socks5 = $data['socks5'] ?? '';
            $assetName = $data['asset_name'] ?? '';
            $country = $data['country'] ?? '';

            if (empty($socks5)) {
                $failed++;
                $errors[] = ['row' => $total + 1, 'message' => 'socks5为空'];
                continue;
            }

            // 解析 socks5 连接串
            $parts = explode(':', $socks5);
            $ipAddress = $parts[0] ?? '';
            $port = (int) ($parts[1] ?? 0);
            $authUser = $parts[2] ?? '';
            $authPass = $parts[3] ?? '';

            if (empty($ipAddress) || $port <= 0) {
                $failed++;
                $errors[] = ['row' => $total + 1, 'message' => "socks5格式错误: {$socks5}"];
                continue;
            }

            // 检查重复（含软删除记录）：同地址+端口但账号/密码不同的是独立资产，只有四元组全同才算重复
            $existingIp = ProxyIp::withTrashed()
                ->where('ip_address', $ipAddress)
                ->where('port', $port)
                ->whereRaw("COALESCE(auth_username, '') = ?", [$authUser])
                ->whereRaw("COALESCE(auth_password, '') = ?", [$authPass])
                ->first();
            if ($existingIp) {
                if ($existingIp->trashed()) {
                    \App\Models\Subscription::where('proxy_ip_id', $existingIp->id)->delete();
                    \App\Models\IpAssignmentLog::where('proxy_ip_id', $existingIp->id)->delete();
                    \App\Models\ForwardRule::where('proxy_ip_id', $existingIp->id)->delete();
                    $existingIp->forceDelete();
                    $existingIp = null;
                } else {
                    // 有活跃订阅的不允许覆盖
                    $hasActive = \App\Models\Subscription::where('proxy_ip_id', $existingIp->id)
                        ->where('status', 'active')->exists();
                    if ($hasActive) {
                        $failed++;
                        $errors[] = ['row' => $total + 1, 'message' => "IP {$ipAddress}:{$port} 有活跃订阅，无法覆盖"];
                        continue;
                    }
                    // 无活跃订阅：重复导入同一资产，刷新为可用
                    $existingIp->update([
                        'socks5_info'    => implode(':', array_filter([$ipAddress, $port, $authUser, $authPass])),
                        'auth_username'  => $authUser,
                        'auth_password'  => $authPass,
                        'status'         => 'available',
                        'assigned_customer_id' => null,
                        'released_at'    => null,
                        'release_reason' => null,
                    ]);
                    // 继续走后续客户分配逻辑
                }
            }

            try {
                DB::transaction(function () use (
                    $data, $socks5, $assetName, $country, $ipAddress, $port, $authUser, $authPass,
                    $assetGroupId, $ipGroupId, $userId, $importLog, $countryMap, &$success, $existingIp
                ) {
                    $countryCode = $countryMap[$country] ?? strtoupper(substr($country, 0, 2));
                    $sourceName = $data['source_name'] ?? '';
                    $customerName = $data['customer_name'] ?? '';
                    $salesPerson = $data['sales_person'] ?? '';
                    $expiresAt = $this->parseExpiryDate($data['expires_at'] ?? '');

                    // 创建或复用已恢复的 IP 资产
                    if ($existingIp && !$existingIp->trashed()) {
                        // 已恢复的软删除记录，补充字段
                        $existingIp->update([
                            'protocol' => 'socks5',
                            'asset_name' => $assetName ?: "{$country}-{$ipAddress}",
                            'country_code' => $countryCode,
                            'country_name' => $country,
                            'ip_type' => 'residential',
                            'nature' => 'static',
                            'source_name' => $sourceName ?: '手动导入',
                            'status' => $customerName ? 'assigned' : 'available',
                            'upstream_expires_at' => $expiresAt,
                            'remark' => $data['remark'] ?? null,
                        ]);
                        $proxyIp = $existingIp;
                    } else {
                        $proxyIp = ProxyIp::create([
                            'asset_group_id' => $assetGroupId,
                            'ip_group_id' => $ipGroupId,
                            'socks5_info' => $socks5,
                            'ip_address' => $ipAddress,
                            'port' => $port,
                            'auth_username' => $authUser,
                            'auth_password' => $authPass,
                            'protocol' => 'socks5',
                            'asset_name' => $assetName ?: "{$country}-{$ipAddress}",
                            'country_code' => $countryCode,
                            'country_name' => $country,
                            'ip_type' => 'residential',
                            'nature' => 'static',
                            'source_name' => $sourceName ?: '手动导入',
                            'status' => $customerName ? 'assigned' : 'available',
                            'import_batch_id' => $importLog->id,
                            'upstream_expires_at' => $expiresAt,
                            'remark' => $data['remark'] ?? null,
                        ]);
                    }

                    // 如果有客户名，自动匹配/创建客户并分配
                    if ($customerName) {
                        $customer = Customer::where('customer_name', $customerName)->first();
                        if (!$customer) {
                            $customer = new Customer([
                                'customer_name' => $customerName,
                                'username' => 'snp_' . Str::random(8),
                                'password' => Hash::make(Str::random(12)),
                                'sales_person' => $salesPerson,
                            ]);
                            $customer->status = 1;
                            $customer->balance = 0;
                            $customer->save();
                        }

                        $proxyIp->update(['assigned_customer_id' => $customer->id]);

                        // 创建订阅
                        if ($expiresAt) {
                            Subscription::create([
                                'customer_id' => $customer->id,
                                'proxy_ip_id' => $proxyIp->id,
                                'price' => 0,
                                'duration' => 1,
                                'unit' => 3,
                                'started_at' => now(),
                                'expires_at' => $expiresAt,
                                'status' => $expiresAt > now() ? 'active' : 'expired',
                                'created_by' => $userId,
                                'remark' => '批量导入',
                                'balance_deducted' => false,
                            ]);
                        }

                        // 记录分配日志
                        IpAssignmentLog::create([
                            'proxy_ip_id' => $proxyIp->id,
                            'customer_id' => $customer->id,
                            'action' => 'assign',
                            'operated_by' => $userId,
                            'remark' => '批量导入自动分配',
                            'created_at' => now(),
                        ]);
                    }

                    $success++;
                });
            } catch (\Exception $e) {
                $failed++;
                $errors[] = ['row' => $total + 1, 'message' => $e->getMessage()];
            }
        }

        fclose($handle);
        @unlink($tmpPath);
        ini_set('auto_detect_line_endings', $prevAutoDetect);

        // 确保 error_details 中所有字符串都是合法 UTF-8
        $errors = array_map(function ($e) {
            if (isset($e['message']) && !mb_check_encoding($e['message'], 'UTF-8')) {
                $e['message'] = mb_convert_encoding($e['message'], 'UTF-8', 'UTF-8');
            }
            return $e;
        }, $errors);

        $importLog->update([
            'total_count' => $total,
            'success_count' => $success,
            'fail_count' => $failed,
            'error_details' => $errors ?: null,
            'status' => $failed === 0 ? 'completed' : ($success > 0 ? 'completed' : 'failed'),
        ]);

        \Illuminate\Support\Facades\Log::info('CSV import: completed', [
            'total' => $total, 'success' => $success, 'failed' => $failed,
            'error_count' => count($errors),
            'first_errors' => array_slice($errors, 0, 3),
        ]);

        return $this->success([
            'total_count' => $total,
            'success_count' => $success,
            'fail_count' => $failed,
            'errors' => array_slice($errors, 0, 50),
        ], "导入完成: 成功{$success}条, 失败{$failed}条");
    }

    /**
     * 解析到期时间（支持多种格式）
     */
    private function parseExpiryDate(string $value): ?\Carbon\Carbon
    {
        if (empty($value)) return null;

        $value = trim($value);

        // "3.26到期" → "2026-03-26"
        if (preg_match('/^(\d{1,2})\.(\d{1,2})/', $value, $m)) {
            $year = date('Y'); // 默认当年，如果已过则下一年
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', "{$year}-{$m[1]}-{$m[2]}");
            if ($date->isPast()) {
                $date->addYear();
            }
            return $date;
        }

        // "2026-03-26" 或 "2026/03/26"
        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ========== 批量操作 ==========

    /**
     * 批量分配 IP
     * POST /proxy-ips/batch-assign
     */
    public function batchAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'            => 'required|array|min:1|max:200',
            'ids.*'          => 'integer',
            'customer_id'    => 'required|integer|exists:customers,id',
            'price'          => 'required|numeric|min:0',
            'duration'       => 'required|integer|min:1',
            'unit'           => 'required|integer|in:1,2,3,4',
            'deduct_balance' => 'boolean',
        ]);

        $price = (float) $data['price'];
        $shouldDeduct = ($data['deduct_balance'] ?? false) && $price > 0;
        $succeeded = 0;
        $failed = [];

        foreach ($data['ids'] as $ipId) {
            $ip = ProxyIp::find($ipId);
            if (!$ip) { $failed[] = "#{$ipId} 不存在"; continue; }
            if ($ip->status !== 'available') { $failed[] = "#{$ipId} {$ip->ip_address} 非可用状态"; continue; }

            try {
                DB::transaction(function () use ($ip, $data, $request, $price, $shouldDeduct) {
                    $expiresAt = \App\Support\DurationHelper::addToDate(now(), (int) $data['duration'], (int) $data['unit']);

                    if ($ip->upstream_expires_at && $ip->upstream_expires_at->isFuture()) {
                        $expiresAt = $ip->upstream_expires_at;
                    }

                    $balanceBefore = null;
                    $balanceAfter = null;
                    if ($shouldDeduct) {
                        $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);
                        if ((float) $customer->balance < $price) {
                            throw new \Exception(sprintf('客户余额不足：当前 ¥%.2f，需要 ¥%.2f', $customer->balance, $price));
                        }
                        $balanceBefore = (float) $customer->balance;
                        $customer->decrement('balance', $price);
                        $balanceAfter = round($balanceBefore - $price, 2);
                    }

                    $subscription = Subscription::create([
                        'customer_id'   => $data['customer_id'],
                        'proxy_ip_id'   => $ip->id,
                        'price'         => $price,
                        'sales_cost'    => $ip->sales_cost,
                        'duration'      => $data['duration'],
                        'unit'          => $data['unit'],
                        'started_at'    => now(),
                        'expires_at'    => $expiresAt,
                        'status'        => 'active',
                        'renewed_count' => 0,
                        'created_by'    => $request->user()?->id,
                        'balance_deducted' => $shouldDeduct,
                    ]);

                    if ($shouldDeduct) {
                        Transaction::create([
                            'customer_id'    => $data['customer_id'],
                            'type'           => Transaction::TYPE_PURCHASE,
                            'amount'         => -$price,
                            'balance_before' => $balanceBefore,
                            'balance_after'  => $balanceAfter,
                            'related_type'   => Subscription::class,
                            'related_id'     => $subscription->id,
                            'description'    => "批量分配IP({$ip->ip_address})，价格¥{$price}",
                            'operated_by'    => $request->user()?->id,
                        ]);
                    }

                    $ip->update([
                        'status'               => 'assigned',
                        'assigned_customer_id' => $data['customer_id'],
                    ]);

                    IpAssignmentLog::create([
                        'proxy_ip_id'     => $ip->id,
                        'customer_id'     => $data['customer_id'],
                        'subscription_id' => $subscription->id,
                        'action'          => 'assign',
                        'operated_by'     => $request->user()?->id,
                        'remark'          => "批量分配，价格{$data['price']}，时长{$data['duration']}" . ($shouldDeduct ? '，已扣余额' : ''),
                        'created_at'      => now(),
                    ]);
                });
                $succeeded++;
            } catch (\Throwable $e) {
                $failed[] = "#{$ipId} {$ip->ip_address} 失败: {$e->getMessage()}";
            }
        }

        return $this->success([
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ], "成功分配 {$succeeded} 条" . (count($failed) ? "，失败 " . count($failed) . " 条" : ''));
    }

    /**
     * 批量释放 IP
     * POST /proxy-ips/batch-release
     */
    public function batchRelease(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array|min:1|max:200',
            'ids.*'  => 'integer',
            'reason' => 'nullable|string|max:255',
        ]);

        $succeeded = 0;
        $failed = [];
        $sparkResults = [];

        foreach ($data['ids'] as $ipId) {
            $ip = ProxyIp::find($ipId);
            if (!$ip) { $failed[] = "#{$ipId} 不存在"; continue; }
            if ($ip->status === 'released') { $failed[] = "#{$ipId} 已释放"; continue; }

            try {
                $activeSub = $ip->activeSubscription;
                $hasSpark = (bool) $ip->spark_instance_id;

                DB::transaction(function () use ($ip, $data, $request, $activeSub, $hasSpark) {
                    if ($activeSub) {
                        $activeSub->update(['status' => 'cancelled']);
                    }

                    $updates = [
                        'status'               => 'released',
                        'assigned_customer_id' => null,
                        'released_at'          => now(),
                        'release_reason'       => $data['reason'] ?? '批量释放',
                        'released_by'          => $request->user()?->id,
                    ];

                    if ($hasSpark) {
                        $updates['spark_release_status'] = 'pending';
                    }

                    $ip->update($updates);

                    IpAssignmentLog::create([
                        'proxy_ip_id'  => $ip->id,
                        'customer_id'  => $ip->getOriginal('assigned_customer_id'),
                        'action'       => 'release',
                        'operated_by'  => $request->user()?->id,
                        'remark'       => '批量释放: ' . ($data['reason'] ?? ''),
                        'created_at'   => now(),
                    ]);
                });

                // 事务外删除转发
                if ($activeSub) {
                    try { app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($activeSub); } catch (\Throwable) {}
                    try { app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($activeSub); } catch (\Throwable) {}
                }

                // 事务外调用 Spark DelProxy
                if ($hasSpark) {
                    $sparkResult = \App\Services\SparkReleaseService::releaseInstance($ip->fresh(), '批量释放');
                    $sparkResults[] = "#{$ipId} {$ip->ip_address}: {$sparkResult['message']}";
                }

                $succeeded++;
            } catch (\Throwable $e) {
                $failed[] = "#{$ipId} 失败: {$e->getMessage()}";
            }
        }

        $msg = "成功释放 {$succeeded} 条";
        if ($sparkResults) {
            $msg .= '。Spark: ' . implode('; ', $sparkResults);
        }

        return $this->success([
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'spark_results' => $sparkResults,
        ], $msg);
    }

    /**
     * 批量删除 IP（仅可用状态）
     * POST /proxy-ips/batch-delete
     */
    public function batchDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $succeeded = 0;
        $failed = [];

        foreach ($data['ids'] as $ipId) {
            $ip = ProxyIp::find($ipId);
            if (!$ip) { continue; }
            if ($ip->status !== 'available') {
                $failed[] = "#{$ipId} {$ip->ip_address} 非可用状态，不能删除";
                continue;
            }
            $ip->delete();
            $succeeded++;
        }

        return $this->success([
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ], "成功删除 {$succeeded} 条");
    }

    /**
     * 批量迁移资产组
     * POST /proxy-ips/batch-move-group
     */
    public function batchMoveGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'            => 'required|array|min:1|max:500',
            'ids.*'          => 'integer',
            'asset_group_id' => 'required|integer|exists:ip_asset_groups,id',
        ]);

        $count = ProxyIp::whereIn('id', $data['ids'])
            ->update(['asset_group_id' => $data['asset_group_id']]);

        $group = \App\Models\IpAssetGroup::find($data['asset_group_id']);

        return $this->success(['updated' => $count], "已将 {$count} 条 IP 移到「{$group->name}」");
    }

    /**
     * POST /proxy-ips/batch-test-pool
     * 批量加入测试IP池
     */
    public function batchAddToTestPool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $updated = 0;
        foreach ($data['ids'] as $ipId) {
            $ip = ProxyIp::find($ipId);
            if (!$ip || $ip->status !== 'assigned') continue;

            DB::transaction(function () use ($ip, $data, $request) {
                $customerId = $ip->assigned_customer_id;

                // 取消活跃订阅（但记录保留，用于到期判断）
                $activeSub = $ip->activeSubscription;
                if ($activeSub) {
                    $activeSub->update(['status' => 'cancelled', 'remark' => trim(($activeSub->remark ?? '') . ' [转入测试池]')]);
                }

                // 解绑客户，标记测试池
                $ip->update([
                    'assigned_customer_id' => null,
                    'status' => 'available',
                    'is_test_pool' => true,
                    'test_pool_added_at' => now(),
                    'test_pool_added_by' => $request->user()?->id,
                    'test_pool_reason' => $data['reason'] ?? '客户不再使用，转入测试池',
                ]);

                if ($customerId) {
                    IpAssignmentLog::create([
                        'proxy_ip_id' => $ip->id,
                        'customer_id' => $customerId,
                        'action' => 'unassign',
                        'operated_by' => $request->user()?->id,
                        'remark' => '转入测试池: ' . ($data['reason'] ?? ''),
                        'created_at' => now(),
                    ]);
                }
            });
            $updated++;
        }

        return $this->success(['updated' => $updated], "已将 {$updated} 条 IP 加入测试池（已解绑原客户）");
    }

    /**
     * POST /proxy-ips/test-pool-assign
     * 将测试池 IP 临时分配给客户（可含转发）
     */
    public function testPoolAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_id' => 'required|integer|exists:proxy_ips,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'duration_days' => 'nullable|integer|min:1|max:30',
            'forward' => 'nullable|array',
            'forward.device_group_id' => 'required_with:forward|integer',
            'forward.speed_limit_mbps' => 'nullable|integer',
        ]);

        $ip = ProxyIp::findOrFail($data['proxy_ip_id']);
        if (!$ip->is_test_pool) {
            return $this->error('该 IP 不在测试池中', 422);
        }
        if ($ip->status === 'assigned') {
            return $this->error('该 IP 已分配给其他测试客户', 422);
        }

        $durationDays = $data['duration_days'] ?? 7;

        $result = DB::transaction(function () use ($ip, $data, $request, $durationDays) {
            // 创建测试订阅（价格为0）
            $subscription = Subscription::create([
                'customer_id' => $data['customer_id'],
                'proxy_ip_id' => $ip->id,
                'price' => 0,
                'duration' => $durationDays,
                'unit' => 1, // 天
                'started_at' => now(),
                'expires_at' => now()->addDays($durationDays),
                'status' => 'active',
                'renewed_count' => 0,
                'remark' => '测试IP分配',
                'created_by' => $request->user()?->id,
                'balance_deducted' => false,
            ]);

            $ip->update([
                'status' => 'assigned',
                'assigned_customer_id' => $data['customer_id'],
            ]);

            IpAssignmentLog::create([
                'proxy_ip_id' => $ip->id,
                'customer_id' => $data['customer_id'],
                'subscription_id' => $subscription->id,
                'action' => 'assign',
                'operated_by' => $request->user()?->id,
                'remark' => "测试分配 {$durationDays} 天",
                'created_at' => now(),
            ]);

            return $subscription;
        });

        // 如果有转发需求，异步处理
        if (!empty($data['forward'])) {
            try {
                $forwardService = app(\App\Services\Ny\NyForwardService::class);
                $forwardService->createForSubscription($result, $data['forward']);
            } catch (\Throwable $e) {
                \Log::warning('Test pool assign: forward creation failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->success($result, '测试 IP 已分配');
    }

    /**
     * POST /proxy-ips/test-pool-unassign
     * 回收测试 IP（解绑客户 + 删转发），IP 回到测试池
     */
    public function testPoolUnassign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_id' => 'required|integer|exists:proxy_ips,id',
        ]);

        $ip = ProxyIp::findOrFail($data['proxy_ip_id']);
        if (!$ip->is_test_pool) {
            return $this->error('该 IP 不在测试池中', 422);
        }
        if ($ip->status !== 'assigned') {
            return $this->error('该 IP 当前未分配', 422);
        }

        $customerId = $ip->assigned_customer_id;

        DB::transaction(function () use ($ip, $request, $customerId) {
            // 取消测试订阅
            $activeSub = $ip->activeSubscription;
            if ($activeSub) {
                $activeSub->update(['status' => 'cancelled', 'remark' => trim(($activeSub->remark ?? '') . ' [测试结束回收]')]);

                // 删除转发
                try {
                    app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($activeSub);
                } catch (\Throwable $e) {
                    \Log::warning("Test pool unassign: delete forward failed: {$e->getMessage()}");
                }
                try {
                    app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($activeSub);
                } catch (\Throwable $e) {
                    \Log::warning("Test pool unassign: delete xui forward failed: {$e->getMessage()}");
                }
            }

            // 解绑客户，IP 回到测试池可用状态
            $ip->update([
                'status' => 'available',
                'assigned_customer_id' => null,
            ]);

            if ($customerId) {
                IpAssignmentLog::create([
                    'proxy_ip_id' => $ip->id,
                    'customer_id' => $customerId,
                    'action' => 'unassign',
                    'operated_by' => $request->user()?->id,
                    'remark' => '测试结束，回收到测试池',
                    'created_at' => now(),
                ]);
            }
        });

        return $this->success(null, '测试 IP 已回收');
    }

    /**
     * POST /proxy-ips/batch-remove-test-pool
     * 批量从测试池移除（作废）
     */
    public function batchRemoveFromTestPool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $updated = ProxyIp::whereIn('id', $data['ids'])
            ->where('is_test_pool', true)
            ->update([
                'is_test_pool' => false,
                'test_pool_added_at' => null,
                'test_pool_added_by' => null,
                'test_pool_reason' => null,
            ]);

        return $this->success(['updated' => $updated], "已移除 {$updated} 条");
    }

    /**
     * GET /proxy-ips/test-pool
     * 测试IP池列表
     */
    public function testPool(Request $request): JsonResponse
    {
        $query = ProxyIp::with([
                'assetGroup:id,name',
                'assignedCustomer:id,customer_name',
                'activeSubscription',
            ])
            ->where('is_test_pool', true)
            ->orderByDesc('test_pool_added_at');

        if ($request->filled('country_code')) {
            $query->where('country_code', $request->input('country_code'));
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }
}
