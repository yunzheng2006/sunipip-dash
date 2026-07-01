<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sqlFile = base_path('../spark_area.sql');

        if (!file_exists($sqlFile)) {
            // 也尝试 backend 同级目录
            $sqlFile = base_path('database/spark_area.sql');
        }

        if (!file_exists($sqlFile)) {
            throw new \RuntimeException('spark_area.sql not found. Place it at project root or backend/database/');
        }

        // 读取并执行 SQL
        $sql = file_get_contents($sqlFile);

        // 按语句分割执行（跳过注释和空行）
        DB::unprepared($sql);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `area_city`');
        DB::statement('DROP TABLE IF EXISTS `area_state`');
        DB::statement('DROP TABLE IF EXISTS `area_country`');
    }
};
