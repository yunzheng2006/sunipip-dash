<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ForwardPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForwardPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = ForwardPlan::orderBy('type')->orderBy('base_price')->get();

        // 附加面板和设备组信息
        $plans->each(function ($plan) {
            if ($plan->type === 'ny') {
                $plan->panel = $plan->panel_id
                    ? \App\Models\NyPanel::select('id', 'name')->find($plan->panel_id)
                    : null;
                $plan->device_group = $plan->device_group_id
                    ? \App\Models\NyDeviceGroup::select('id', 'name', 'original_connect_host', 'ny_panel_id')->find($plan->device_group_id)
                    : null;
            } else {
                $plan->panel = $plan->panel_id
                    ? \App\Models\XuiPanel::select('id', 'name')->find($plan->panel_id)
                    : null;
                $plan->device_group = null;
            }
        });

        return $this->success($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:ny,xui',
            'panel_id' => 'nullable|integer',
            'device_group_id' => 'nullable|integer',
            'speed_limit_mbps' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'display_host' => 'nullable|string|max:200',
            'pricing_mode' => 'nullable|string|in:addon,fixed',
            'base_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'hard_cost_price' => 'nullable|numeric|min:0',
            'included_traffic_gb' => 'nullable|integer|min:0',
            'overage_price_per_gb' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
            'module' => 'nullable|string|in:video,live_mobile,live_pc',
        ]);
        return $this->success(ForwardPlan::create($data), '创建成功');
    }

    public function update(Request $request, ForwardPlan $forwardPlan): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|string|in:ny,xui',
            'panel_id' => 'nullable|integer',
            'device_group_id' => 'nullable|integer',
            'speed_limit_mbps' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'display_host' => 'nullable|string|max:200',
            'pricing_mode' => 'nullable|string|in:addon,fixed',
            'base_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'hard_cost_price' => 'nullable|numeric|min:0',
            'included_traffic_gb' => 'nullable|integer|min:0',
            'overage_price_per_gb' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
            'module' => 'nullable|string|in:video,live_mobile,live_pc',
        ]);
        $forwardPlan->update($data);
        return $this->success($forwardPlan, '更新成功');
    }

    public function destroy(ForwardPlan $forwardPlan): JsonResponse
    {
        $forwardPlan->delete();
        return $this->success(null, '已删除');
    }

    // Customer-facing: list active plans
    public function activePlans(): JsonResponse
    {
        $plans = ForwardPlan::where('is_active', 1)
            ->select('id', 'name', 'type', 'speed_limit_mbps', 'device_limit', 'display_host', 'pricing_mode', 'base_price', 'included_traffic_gb', 'overage_price_per_gb', 'description')
            ->orderBy('base_price')
            ->get();
        return $this->success($plans);
    }
}
