<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wg_servers', function (Blueprint $table) {
            $table->string('ssh_host', 255)->nullable()->after('remark');
            $table->unsignedInteger('ssh_port')->default(22)->after('ssh_host');
            $table->string('ssh_user', 100)->default('root')->after('ssh_port');
            $table->text('ssh_private_key')->nullable()->after('ssh_user');
            $table->string('role', 20)->default('primary')->after('ssh_private_key');
        });
    }

    public function down(): void
    {
        Schema::table('wg_servers', function (Blueprint $table) {
            $table->dropColumn(['ssh_host', 'ssh_port', 'ssh_user', 'ssh_private_key', 'role']);
        });
    }
};
