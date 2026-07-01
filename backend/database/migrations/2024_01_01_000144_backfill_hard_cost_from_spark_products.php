<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill hard_cost for Spark subscriptions via spark_instances → spark_orders → product cost
        // SparkStockCacheService::products() returns live pricing; use spark_orders.request_data as fallback
        $products = [];
        try {
            $products = \App\Services\SparkStockCacheService::products();
        } catch (\Throwable $e) {
            Log::warning('backfill_hard_cost: SparkStockCacheService unavailable: ' . $e->getMessage());
        }

        $productCosts = [];
        foreach ($products as $p) {
            if (isset($p['product_id'], $p['cost_price'])) {
                $productCosts[$p['product_id']] = (float) $p['cost_price'];
            }
        }

        // Get all subs with spark IPs that don't have hard_cost yet
        $subs = DB::table('subscriptions as s')
            ->join('proxy_ips as pi', 's.proxy_ip_id', '=', 'pi.id')
            ->join('spark_instances as si', 'si.proxy_ip_id', '=', 'pi.id')
            ->join('spark_orders as so', 'si.spark_order_id', '=', 'so.id')
            ->whereNull('s.hard_cost')
            ->whereNotNull('so.product_id')
            ->select('s.id', 'so.product_id')
            ->get();

        $updated = 0;
        foreach ($subs as $sub) {
            $cost = $productCosts[$sub->product_id] ?? null;
            if ($cost !== null && $cost > 0) {
                DB::table('subscriptions')->where('id', $sub->id)->update(['hard_cost' => $cost]);
                $updated++;
            }
        }

        Log::info("backfill_hard_cost: updated {$updated} of {$subs->count()} spark subscriptions");

        // Backfill for IPIPV subscriptions
        $ipipvProducts = [];
        try {
            $ipipvProducts = \App\Services\IpipvStockCacheService::products();
        } catch (\Throwable $e) {
            Log::warning('backfill_hard_cost: IpipvStockCacheService unavailable: ' . $e->getMessage());
        }

        $ipipvCosts = [];
        foreach ($ipipvProducts as $p) {
            if (isset($p['productNo'], $p['unitPrice'])) {
                $ipipvCosts[$p['productNo']] = (float) $p['unitPrice'];
            }
        }

        if (!empty($ipipvCosts)) {
            $ipipvSubs = DB::table('subscriptions as s')
                ->join('proxy_ips as pi', 's.proxy_ip_id', '=', 'pi.id')
                ->join('ipipv_orders as io', function ($join) {
                    $join->on('io.id', '=', DB::raw('(SELECT ipipv_orders.id FROM ipipv_orders
                        JOIN ipipv_instances ON ipipv_instances.ipipv_order_id = ipipv_orders.id
                        WHERE ipipv_instances.instance_id = pi.ipipv_instance_id LIMIT 1)'));
                })
                ->whereNull('s.hard_cost')
                ->whereNotNull('pi.ipipv_instance_id')
                ->select('s.id', 'io.product_no')
                ->get();

            $ipipvUpdated = 0;
            foreach ($ipipvSubs as $sub) {
                $cost = $ipipvCosts[$sub->product_no] ?? null;
                if ($cost !== null && $cost > 0) {
                    DB::table('subscriptions')->where('id', $sub->id)->update(['hard_cost' => $cost]);
                    $ipipvUpdated++;
                }
            }

            Log::info("backfill_hard_cost: updated {$ipipvUpdated} ipipv subscriptions");
        }
    }

    public function down(): void
    {
    }
};
