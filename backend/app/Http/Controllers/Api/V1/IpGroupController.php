<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IpGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IpGroup::query();

        if ($request->filled('country_code')) {
            $query->where('country_code', $request->country_code);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        $query->withCount(['proxyIps', 'proxyIps as available_ips_count' => function ($q) {
            $q->where('status', 'available');
        }]);

        $paginator = $query->orderByDesc('id')->paginate($request->input('per_page', 20));

        return $this->paginated($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:ip_groups,slug',
            'country_code' => 'nullable|string|size:2',
            'country_name' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'isp_type' => 'nullable|string|max:50',
            'net_type' => 'nullable|string|max:20',
            'description' => 'nullable|string',
        ]);

        $group = IpGroup::create($request->all());

        return $this->success($group, '创建成功', 201);
    }

    public function show(IpGroup $ipGroup): JsonResponse
    {
        $ipGroup->loadCount(['proxyIps', 'proxyIps as available_ips_count' => function ($q) {
            $q->where('status', 'available');
        }, 'proxyIps as assigned_ips_count' => function ($q) {
            $q->where('status', 'assigned');
        }]);

        $ipGroup->load('pricingRules');

        return $this->success($ipGroup);
    }

    public function update(Request $request, IpGroup $ipGroup): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'slug' => 'sometimes|required|string|max:100|unique:ip_groups,slug,' . $ipGroup->id,
            'country_code' => 'nullable|string|size:2',
            'country_name' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'isp_type' => 'nullable|string|max:50',
            'net_type' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'sometimes|integer|in:0,1',
        ]);

        $ipGroup->update($request->all());

        return $this->success($ipGroup, '更新成功');
    }

    public function destroy(IpGroup $ipGroup): JsonResponse
    {
        if ($ipGroup->proxyIps()->where('status', 'assigned')->exists()) {
            return $this->error('该IP组下有已分配的IP，无法删除');
        }

        $ipGroup->delete();

        return $this->success(null, '删除成功');
    }

    // 获取所有IP组（下拉选择用，不分页）
    public function all(): JsonResponse
    {
        $groups = IpGroup::where('status', 1)
            ->select('id', 'name', 'slug', 'country_code', 'country_name', 'city', 'isp_type')
            ->withCount('proxyIps')
            ->get();

        return $this->success($groups);
    }
}
