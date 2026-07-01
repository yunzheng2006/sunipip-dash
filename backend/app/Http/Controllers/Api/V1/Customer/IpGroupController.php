<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerIpGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = CustomerIpGroup::where('customer_id', $request->user()->id)
            ->withCount('proxyIps')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
        ]);

        $customer = $request->user();

        if (CustomerIpGroup::where('customer_id', $customer->id)->count() >= 20) {
            return $this->error('最多创建 20 个分组', 422);
        }

        if (CustomerIpGroup::where('customer_id', $customer->id)->where('name', $data['name'])->exists()) {
            return $this->error('分组名称已存在', 422);
        }

        $maxSort = CustomerIpGroup::where('customer_id', $customer->id)->max('sort_order') ?? 0;

        $group = CustomerIpGroup::create([
            'customer_id' => $customer->id,
            'name' => $data['name'],
            'sort_order' => $maxSort + 1,
        ]);

        return $this->success($group, '分组已创建');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = CustomerIpGroup::where('customer_id', $request->user()->id)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (isset($data['name']) && $data['name'] !== $group->name) {
            if (CustomerIpGroup::where('customer_id', $request->user()->id)
                ->where('name', $data['name'])->where('id', '!=', $id)->exists()) {
                return $this->error('分组名称已存在', 422);
            }
        }

        $group->update($data);
        return $this->success($group, '已更新');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $group = CustomerIpGroup::where('customer_id', $request->user()->id)->findOrFail($id);
        $group->delete();
        return $this->success(null, '分组已删除');
    }

    public function addIps(Request $request, int $id): JsonResponse
    {
        $group = CustomerIpGroup::where('customer_id', $request->user()->id)->findOrFail($id);

        $data = $request->validate([
            'proxy_ip_ids' => 'required|array|min:1|max:500',
            'proxy_ip_ids.*' => 'integer',
        ]);

        $ownedIds = \App\Models\ProxyIp::where('assigned_customer_id', $request->user()->id)
            ->whereIn('id', $data['proxy_ip_ids'])
            ->pluck('id');

        $existing = $group->proxyIps()->whereIn('proxy_ip_id', $ownedIds)->pluck('proxy_ip_id');
        $newIds = $ownedIds->diff($existing);

        if ($newIds->isNotEmpty()) {
            $group->proxyIps()->attach($newIds);
        }

        return $this->success([
            'added' => $newIds->count(),
            'skipped' => $ownedIds->count() - $newIds->count(),
        ], "已添加 {$newIds->count()} 条");
    }

    public function removeIps(Request $request, int $id): JsonResponse
    {
        $group = CustomerIpGroup::where('customer_id', $request->user()->id)->findOrFail($id);

        $data = $request->validate([
            'proxy_ip_ids' => 'required|array|min:1|max:500',
            'proxy_ip_ids.*' => 'integer',
        ]);

        $detached = $group->proxyIps()->detach($data['proxy_ip_ids']);
        return $this->success(['removed' => $detached], "已移除 {$detached} 条");
    }
}
