<?php

namespace App\Http\Controllers\Api\V1\Oidc;

use App\Services\Oidc\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInfoController
{
    public function userinfo(Request $request, OidcService $oidc): JsonResponse
    {
        $customer = $request->attributes->get('oauth_customer');
        $tokenRecord = $request->attributes->get('oauth_token');

        $claims = ['sub' => (string) $customer->id];
        $claims = array_merge($claims, $oidc->getCustomerClaims($customer, $tokenRecord->scopes ?? []));

        return response()->json($claims);
    }
}
