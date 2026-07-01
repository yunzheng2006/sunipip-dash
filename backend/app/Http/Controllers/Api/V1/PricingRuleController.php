<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PricingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = QueryBuilder::for(PricingRule::class)
            ->allowedFilters([
                AllowedFilter::exact('ip_group_id'),
                AllowedFilter::exact('country_code'),
                AllowedFilter::exact('ip_type'),
                AllowedFilter::exact('nature'),
                AllowedFilter::exact('is_active'),
            ])
            ->with('ipGroup')
            ->allowedSorts(['id', 'country_code', 'price', 'created_at'])
            ->defaultSort('-id')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ip_group_id'  => 'required|exists:ip_groups,id',
            'price'        => 'required|numeric|min:0',
            'cost_price'   => 'nullable|numeric|min:0',
            'duration'     => 'nullable|integer|min:1',
            'unit'         => 'nullable|integer|in:1,2,3,4',
            'country_code' => 'nullable|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'ip_type'      => 'nullable|string|in:residential,datacenter,mobile',
            'nature'       => 'nullable|string|in:static,rotating',
            'net_type'     => 'nullable|string|max:50',
            'is_active'    => 'nullable|integer|in:0,1',
        ]);

        $data['duration'] = $data['duration'] ?? 1;
        $data['unit'] = $data['unit'] ?? 3;

        $rule = PricingRule::create($data);
        $rule->load('ipGroup');

        return $this->success($rule, '定价规则创建成功');
    }

    public function show(PricingRule $pricingRule): JsonResponse
    {
        $pricingRule->load('ipGroup');

        return $this->success($pricingRule);
    }

    public function update(Request $request, PricingRule $pricingRule): JsonResponse
    {
        $data = $request->validate([
            'ip_group_id'  => 'sometimes|exists:ip_groups,id',
            'price'        => 'sometimes|numeric|min:0',
            'cost_price'   => 'nullable|numeric|min:0',
            'duration'     => 'sometimes|integer|min:1',
            'unit'         => 'sometimes|integer|in:1,2,3,4',
            'country_code' => 'nullable|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'ip_type'      => 'nullable|string|in:residential,datacenter,mobile',
            'nature'       => 'nullable|string|in:static,rotating',
            'net_type'     => 'nullable|string|max:50',
            'is_active'    => 'nullable|integer|in:0,1',
        ]);

        $pricingRule->update($data);
        $pricingRule->load('ipGroup');

        return $this->success($pricingRule, '定价规则更新成功');
    }

    public function destroy(PricingRule $pricingRule): JsonResponse
    {
        $pricingRule->delete();

        return $this->success(null, '定价规则已删除');
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'ip_group_id' => 'required|integer',
        ]);

        $rule = PricingRule::where('ip_group_id', $request->input('ip_group_id'))
            ->where('is_active', 1)
            ->with('ipGroup')
            ->first();

        return $this->success($rule);
    }
}
