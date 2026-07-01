<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wg_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('endpoint', 255);
            $table->string('public_key', 64);
            $table->text('private_key');
            $table->unsignedInteger('listen_port')->default(51820);
            $table->string('server_cidr', 20);
            $table->string('dns', 100)->nullable();
            $table->unsignedInteger('mtu')->default(1420);
            $table->unsignedInteger('next_ip_index')->default(2);
            $table->tinyInteger('is_active')->default(1)->index();
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wg_servers');
    }
};
