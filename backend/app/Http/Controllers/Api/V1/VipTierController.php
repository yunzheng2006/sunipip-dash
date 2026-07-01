<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VipTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VipTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = VipTier::orderByDesc('sort_order')->get();
        return $this->success($tiers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'spending_threshold' => 'required|numeric|min:0',
            'topup_threshold' => 'nullable|numeric|min:0',
            'discount_percent' => 'required|integer|min:1|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
            'badge_color' => 'nullable|string|max:20',
        ]);
        return $this->success(VipTier::create($data), '创建成功');
    }

    public function update(Request $request, VipTier $vipTier): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'spending_threshold' => 'sometimes|numeric|min:0',
            'topup_threshold' => 'nullable|numeric|min:0',
            'discount_percent' => 'sometimes|integer|min:1|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
            'badge_color' => 'nullable|string|max:20',
        ]);
        $vipTier->update($data);
        return $this->success($vipTier, '更新成功');
    }

    public function destroy(VipTier $vipTier): JsonResponse
    {
        $vipTier->delete();
        return $this->success(null, '已删除');
    }

    /**
     * POST /vip-tiers/recalculate-all
     * 重新计算所有客户的VIP等级
     */
    public function recalculateAll(): JsonResponse
    {
        $vipService = app(\App\Services\VipService::class);
        $customers = \App\Models\Customer::where('status', 1)->get();
        $updated = 0;
        foreach ($customers as $customer) {
            $vipService->recalculate($customer);
            $updated++;
        }
        return $this->success(['updated' => $updated], "已重新计算 {$updated} 个客户的VIP等级");
    }
}
