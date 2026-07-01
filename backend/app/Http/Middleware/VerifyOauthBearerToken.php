<?php

namespace App\Http\Middleware;

use App\Services\Oidc\OidcService;
use Closure;
use Illuminate\Http\Request;

class VerifyOauthBearerToken
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Missing bearer token'], 401);
        }

        $jwt = substr($header, 7);
        $oidc = app(OidcService::class);
        $tokenRecord = $oidc->validateAccessToken($jwt);

        if (!$tokenRecord) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Token is invalid or expired'], 401);
        }

        $customer = \App\Models\Customer::find($tokenRecord->customer_id);
        if (!$customer || (int) $customer->status !== 1) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'User not found or disabled'], 401);
        }

        $request->attributes->set('oauth_customer', $customer);
        $request->attributes->set('oauth_token', $tokenRecord);

        return $next($request);
    }
}
