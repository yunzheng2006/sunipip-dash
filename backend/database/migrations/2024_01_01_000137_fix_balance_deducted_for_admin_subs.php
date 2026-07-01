<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-created subscriptions (Spark API 开通, 批量导入, 测试转正 etc.)
        // that have balance_deducted=false but are real sales, should have balance_deducted=true.
        // These were created via SparkProvisionService which didn't set balance_deducted.
        $updated = DB::table('subscriptions')
            ->where('is_test', false)
            ->where('balance_deducted', false)
            ->whereIn('status', ['active', 'expired', 'refunded'])
            ->where(function ($q) {
                $q->whereNotNull('admin_set_price')
                  ->orWhere('remark', 'like', 'Spark API%')
                  ->orWhere('remark', 'like', '批量导入%')
                  ->orWhere('remark', 'like', '历史数据%')
                  ->orWhere('remark', '测试转正');
            })
            ->update(['balance_deducted' => true]);

        Log::info("fix_balance_deducted_for_admin_subs: updated {$updated} rows");
    }

    public function down(): void
    {
    }
};
