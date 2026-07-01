<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Spark API 将港台产品归入 CHN，我们按产品名拆分后需要这两条记录
        $entries = [
            ['code' => 'HKG', 'name' => 'Hong Kong', 'full_name' => 'Hong Kong SAR', 'cname' => '香港', 'full_cname' => '中国香港', 'continent_id' => 1],
            ['code' => 'TWN', 'name' => 'Taiwan', 'full_name' => 'Taiwan, China', 'cname' => '台湾', 'full_cname' => '中国台湾', 'continent_id' => 1],
        ];

        foreach ($entries as $entry) {
            DB::table('area_country')->updateOrInsert(
                ['code' => $entry['code']],
                $entry
            );
        }
    }

    public function down(): void
    {
        DB::table('area_country')->whereIn('code', ['HKG', 'TWN'])->delete();
    }
};
