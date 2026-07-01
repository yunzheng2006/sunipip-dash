<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SparkProductBlock;
use App\Services\SparkStockCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SparkProductBlockController extends Controller
{
    public function index(): JsonResponse
    {
        $blocks = SparkProductBlock::orderByDesc('id')->get();
        return $this->success($blocks);
    }

    public function allProducts(Request $request): JsonResponse
    {
        $force = $request->boolean('force');
        $products = SparkStockCacheService::allProducts($force);
        $blockedByProduct = SparkProductBlock::blockedCidrsByProduct();

        $products = array_map(function ($p) use ($blockedByProduct) {
            $pid = $p['product_id'] ?? '';
            $blockedCidrs = isset($blockedByProduct[$pid]) ? array_flip($blockedByProduct[$pid]) : [];
            $p['cidr_blocks'] = array_map(function ($c) use ($blockedCidrs) {
                $c['is_blocked'] = isset($blockedCidrs[$c['cidr'] ?? '']);
                return $c;
            }, $p['cidr_blocks'] ?? []);
            $p['blocked_cidr_count'] = count($blockedCidrs);
            return $p;
        }, $products);

        return $this->success([
            'products' => $products,
            'total_blocked' => SparkProductBlock::count(),
            'last_refreshed_at' => SparkStockCacheService::lastRefreshedAt(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string',
            'items.*.cidr' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.country_code' => 'required|string',
            'reason' => 'nullable|string|max:200',
        ]);

        $created = 0;
        foreach ($data['items'] as $item) {
            SparkProductBlock::firstOrCreate(
                ['product_id' => $item['product_id'], 'cidr' => $item['cidr']],
                [
                    'product_name' => $item['product_name'],
                    'country_code' => $item['country_code'],
                    'reason' => $data['reason'] ?? null,
                    'blocked_by' => $request->user()?->id,
                ]
            );
            $created++;
        }

        SparkStockCacheService::forget();

        return $this->success(null, "已屏蔽 {$created} 条 CIDR");
    }

    public function destroy(SparkProductBlock $sparkProductBlock): JsonResponse
    {
        $sparkProductBlock->delete();
        SparkStockCacheService::forget();
        return $this->success(null, '已解除屏蔽');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1'])['ids'];
        $count = SparkProductBlock::whereIn('id', $ids)->delete();
        SparkProductBlock::clearCache();
        SparkStockCacheService::forget();
        return $this->success(null, "已解除 {$count} 条屏蔽");
    }
}
