<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApModel;
use App\Models\RouterBundle;
use App\Models\RouterModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RouterCatalogController extends Controller
{
    // ── Router Models ──

    public function routerModels(Request $request): JsonResponse
    {
        $models = QueryBuilder::for(RouterModel::class)
            ->withCount('devices')
            ->allowedFilters([AllowedFilter::exact('is_active')])
            ->allowedSorts(['id', 'name', 'sell_price', 'created_at'])
            ->defaultSort('id')
            ->paginate($request->input('per_page', 50));

        return $this->paginated($models);
    }

    public function storeRouterModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'cpu' => 'nullable|string|max:100',
            'ram_mb' => 'nullable|integer|min:256',
            'storage_gb' => 'nullable|integer|min:1',
            'ports' => 'nullable|integer|min:1|max:16',
            'cost_price' => 'nullable|numeric|min:0',
            'sell_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $model = RouterModel::create($data);

        return $this->success($model, '路由器型号已创建');
    }

    public function updateRouterModel(Request $request, RouterModel $routerModel): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'cpu' => 'nullable|string|max:100',
            'ram_mb' => 'nullable|integer|min:256',
            'storage_gb' => 'nullable|integer|min:1',
            'ports' => 'nullable|integer|min:1|max:16',
            'cost_price' => 'nullable|numeric|min:0',
            'sell_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $routerModel->update($data);

        return $this->success($routerModel->fresh(), '路由器型号已更新');
    }

    public function destroyRouterModel(RouterModel $routerModel): JsonResponse
    {
        if ($routerModel->devices()->count() > 0) {
            return $this->error('该型号已关联设备，无法删除', 422);
        }

        $routerModel->delete();

        return $this->success(null, '路由器型号已删除');
    }

    // ── AP Models ──

    public function apModels(Request $request): JsonResponse
    {
        $models = QueryBuilder::for(ApModel::class)
            ->withCount('devices')
            ->allowedFilters([AllowedFilter::exact('is_active')])
            ->allowedSorts(['id', 'name', 'sell_price', 'created_at'])
            ->defaultSort('id')
            ->paginate($request->input('per_page', 50));

        return $this->paginated($models);
    }

    public function storeApModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'band' => 'nullable|string|max:50',
            'specs' => 'nullable|array',
            'cost_price' => 'nullable|numeric|min:0',
            'sell_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $model = ApModel::create($data);

        return $this->success($model, 'AP 型号已创建');
    }

    public function updateApModel(Request $request, ApModel $apModel): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'band' => 'nullable|string|max:50',
            'specs' => 'nullable|array',
            'cost_price' => 'nullable|numeric|min:0',
            'sell_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $apModel->update($data);

        return $this->success($apModel->fresh(), 'AP 型号已更新');
    }

    public function destroyApModel(ApModel $apModel): JsonResponse
    {
        if ($apModel->devices()->count() > 0) {
            return $this->error('该型号已关联设备，无法删除', 422);
        }

        $apModel->delete();

        return $this->success(null, 'AP 型号已删除');
    }

    // ── Bundles ──

    public function bundles(Request $request): JsonResponse
    {
        $bundles = QueryBuilder::for(RouterBundle::class)
            ->with(['routerModel:id,name,cpu,ram_mb,storage_gb,ports', 'apModel:id,name,band'])
            ->withCount('devices')
            ->allowedFilters([AllowedFilter::exact('is_active')])
            ->allowedSorts(['id', 'name', 'bundle_price', 'created_at'])
            ->defaultSort('id')
            ->paginate($request->input('per_page', 50));

        return $this->paginated($bundles);
    }

    public function storeBundle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'router_model_id' => 'required|integer|exists:router_models,id',
            'ap_model_id' => 'nullable|integer|exists:ap_models,id',
            'bundle_price' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $bundle = RouterBundle::create($data);
        $bundle->load(['routerModel:id,name', 'apModel:id,name']);

        return $this->success($bundle, '套餐已创建');
    }

    public function updateBundle(Request $request, RouterBundle $routerBundle): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'router_model_id' => 'sometimes|integer|exists:router_models,id',
            'ap_model_id' => 'nullable|integer|exists:ap_models,id',
            'bundle_price' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $routerBundle->update($data);

        return $this->success(
            $routerBundle->fresh()->load(['routerModel:id,name', 'apModel:id,name']),
            '套餐已更新'
        );
    }

    public function destroyBundle(RouterBundle $routerBundle): JsonResponse
    {
        if ($routerBundle->devices()->count() > 0) {
            return $this->error('该套餐已关联设备，无法删除', 422);
        }

        $routerBundle->delete();

        return $this->success(null, '套餐已删除');
    }

    // ── Dropdown options (for device creation form) ──

    public function options(): JsonResponse
    {
        return $this->success([
            'router_models' => RouterModel::where('is_active', true)
                ->select('id', 'name', 'cpu', 'ram_mb', 'storage_gb', 'ports', 'sell_price')
                ->orderBy('name')
                ->get(),
            'ap_models' => ApModel::where('is_active', true)
                ->select('id', 'name', 'band', 'sell_price')
                ->orderBy('name')
                ->get(),
            'bundles' => RouterBundle::where('is_active', true)
                ->with(['routerModel:id,name', 'apModel:id,name'])
                ->select('id', 'name', 'router_model_id', 'ap_model_id', 'bundle_price')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
