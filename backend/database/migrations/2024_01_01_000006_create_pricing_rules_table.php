<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('country_name', 100);
            $table->string('ip_type', 20)->comment('residential/datacenter/isp');
            $table->string('nature', 20)->default('static')->comment('static/dynamic');
            $table->string('net_type', 20)->nullable()->comment('native/broadcast');
            $table->unsignedInteger('duration')->default(1)->comment('时长数值');
            $table->tinyInteger('unit')->default(3)->comment('1=天 2=周 3=月 4=年');
            $table->decimal('price', 10, 2)->comment('售价(RMB)');
            $table->decimal('cost_price', 10, 2)->nullable()->comment('成本价');
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->index(['country_code', 'ip_type', 'nature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
