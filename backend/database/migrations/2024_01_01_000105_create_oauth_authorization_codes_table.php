<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 128)->unique();
            $table->string('client_id', 80)->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('redirect_uri', 2000);
            $table->json('scopes');
            $table->string('code_challenge', 128)->nullable();
            $table->string('code_challenge_method', 10)->nullable();
            $table->string('nonce', 255)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_authorization_codes');
    }
};
