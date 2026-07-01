<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->string('client_id', 80)->unique();
            $table->string('client_secret', 128);
            $table->json('redirect_uris');
            $table->json('scopes')->nullable();
            $table->boolean('is_confidential')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
