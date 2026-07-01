<?php

use App\Models\PricingMultiplier;
use App\Models\SparkInstance;
use App\Models\Subscription;
use App\Services\SparkStockCacheService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $products = SparkStockCacheService::products();
        if (empty($products)) {
            Log::warning('refresh_list_prices: SparkStockCacheService returned empty, skipping');
            return;
        }

        $productMap = collect($products)->keyBy('product_id');

        $updated = 0;
        $skipped = 0;

        Subscription::where('status', 'active')
            ->where('is_test', false)
            ->with('proxyIp')
            ->chunkById(200, function ($subs) use ($productMap, &$updated, &$skipped) {
                foreach ($subs as $sub) {
                    $proxyIp = $sub->proxyIp;
                    if (!$proxyIp) { $skipped++; continue; }

                    $sparkInstance = SparkInstance::where('proxy_ip_id', $proxyIp->id)->first();
                    if (!$sparkInstance) { $skipped++; continue; }

                    $productId = $sparkInstance->sparkOrder?->product_id;
                    if (!$productId) { $skipped++; continue; }

                    $sparkProduct = $productMap[$productId] ?? null;
                    if (!$sparkProduct) { $skipped++; continue; }

                    $newListPrice = PricingMultiplier::calcSalePrice($sparkProduct);
                    $newSalesCost = PricingMultiplier::calcSalesPrice($sparkProduct);

                    $changes = [];
                    if ($newListPrice !== null && $newListPrice > 0 && (float) $sub->list_price !== $newListPrice) {
                        $changes['list_price'] = $newListPrice;
                    }
                    if ($newSalesCost !== null && $newSalesCost > 0 && (float) $sub->sales_cost !== $newSalesCost) {
                        $changes['sales_cost'] = $newSalesCost;
                    }

                    if (!empty($changes)) {
                        $sub->update($changes);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            });

        Log::info("refresh_list_prices: updated={$updated}, skipped={$skipped}");
    }

    public function down(): void
    {
        // 无法安全回滚
    }
};
