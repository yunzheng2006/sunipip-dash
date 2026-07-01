<?php

namespace App\Http\Controllers\Api\V1\Oidc;

use App\Models\OauthClient;
use App\Services\Oidc\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController
{
    public function token(Request $request, OidcService $oidc): JsonResponse
    {
        $grantType = $request->input('grant_type');
        if ($grantType !== 'authorization_code') {
            return response()->json(['error' => 'unsupported_grant_type'], 400);
        }

        // Authenticate client via Basic auth or POST body
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6));
            if ($decoded && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);
                $clientId = urldecode($clientId);
                $clientSecret = urldecode($clientSecret);
            }
        }

        $client = OauthClient::where('client_id', $clientId)->where('is_active', true)->first();
        if (!$client) {
            return response()->json(['error' => 'invalid_client'], 401);
        }

        if ($client->is_confidential && !$client->verifySecret($clientSecret ?? '')) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Bad client credentials'], 401);
        }

        try {
            $result = $oidc->exchangeAuthorizationCode(
                $request->input('code', ''),
                $clientId,
                $request->input('redirect_uri', ''),
                $request->input('code_verifier')
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => $e->getMessage()], 400);
        }

        return response()->json($result)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }
}
