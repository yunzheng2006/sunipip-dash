<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Restore soft-deleted ProxyIps that have active subscriptions
        $restored = DB::table('proxy_ips')
            ->whereNotNull('deleted_at')
            ->whereIn('id', function ($q) {
                $q->select('proxy_ip_id')
                    ->from('subscriptions')
                    ->where('status', 'active');
            })
            ->update(['deleted_at' => null]);

        if ($restored > 0) {
            logger()->info("Restored {$restored} soft-deleted ProxyIps with active subscriptions");
        }
    }

    public function down(): void
    {
        // Not reversible
    }
};
