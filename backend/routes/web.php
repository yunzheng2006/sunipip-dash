<?php

use App\Http\Controllers\Api\V1\Oidc\DiscoveryController;
use Illuminate\Support\Facades\Route;

// 前端已分离到 Cloudflare Pages，后端只提供 API
Route::get('/', function () {
    return response()->json(['status' => 'ok', 'service' => 'SuniPIP API']);
});

// OIDC Discovery (must be at root, per OpenID Connect spec)
Route::get('.well-known/openid-configuration', [DiscoveryController::class, 'configuration']);
Route::get('.well-known/jwks.json', [DiscoveryController::class, 'jwks']);
