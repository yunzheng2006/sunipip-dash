<?php

namespace App\Http\Controllers\Api\V1\Oidc;

use App\Services\Oidc\OidcService;
use Illuminate\Http\JsonResponse;

class DiscoveryController
{
    public function configuration(OidcService $oidc): JsonResponse
    {
        return response()->json($oidc->getDiscoveryDocument());
    }

    public function jwks(OidcService $oidc): JsonResponse
    {
        return response()->json($oidc->getJwks())
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
