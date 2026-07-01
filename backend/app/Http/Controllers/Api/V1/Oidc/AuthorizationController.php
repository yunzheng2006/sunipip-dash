<?php

namespace App\Http\Controllers\Api\V1\Oidc;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use App\Services\Oidc\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationController extends Controller
{
    public function authorize(Request $request): JsonResponse
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        $responseType = $request->query('response_type');
        $scope = $request->query('scope', 'openid');
        $state = $request->query('state');

        if ($responseType !== 'code') {
            return response()->json(['error' => 'unsupported_response_type'], 400);
        }

        $client = OauthClient::where('client_id', $clientId)->where('is_active', true)->first();
        if (!$client) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Client not found'], 400);
        }

        if (!$client->hasRedirectUri($redirectUri)) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'Invalid redirect_uri'], 400);
        }

        $requestedScopes = array_filter(explode(' ', $scope));
        $supportedScopes = config('oidc.scopes_supported');
        $validScopes = array_intersect($requestedScopes, $supportedScopes);

        return response()->json([
            'client' => [
                'name' => $client->name,
                'client_id' => $client->client_id,
            ],
            'scopes' => $validScopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function approveOrDeny(Request $request, OidcService $oidc): JsonResponse
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'client_id' => 'required|string',
            'redirect_uri' => 'required|string',
            'scope' => 'nullable|string',
            'state' => 'nullable|string',
            'nonce' => 'nullable|string',
            'code_challenge' => 'nullable|string',
            'code_challenge_method' => 'nullable|string|in:S256',
        ]);

        $client = OauthClient::where('client_id', $data['client_id'])->where('is_active', true)->first();
        if (!$client) {
            return $this->error('Invalid client', 400);
        }

        if (!$client->hasRedirectUri($data['redirect_uri'])) {
            return $this->error('Invalid redirect_uri', 400);
        }

        $redirectUri = $data['redirect_uri'];
        $state = $data['state'] ?? null;
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        if (!$data['approve']) {
            $denyUrl = $redirectUri . $separator . http_build_query(array_filter([
                'error' => 'access_denied',
                'error_description' => 'User denied the request',
                'state' => $state,
            ]));
            return response()->json(['redirect_to' => $denyUrl]);
        }

        // Get the authenticated customer (via Sanctum)
        $customer = $request->user();

        $scopes = array_filter(explode(' ', $data['scope'] ?? 'openid'));
        $supportedScopes = config('oidc.scopes_supported');
        $validScopes = array_values(array_intersect($scopes, $supportedScopes));

        $authCode = $oidc->createAuthorizationCode(
            $client,
            $customer,
            $data['redirect_uri'],
            $validScopes,
            $data['code_challenge'] ?? null,
            $data['code_challenge_method'] ?? null,
            $data['nonce'] ?? null
        );

        $approveUrl = $redirectUri . $separator . http_build_query(array_filter([
            'code' => $authCode->code,
            'state' => $state,
        ]));

        return response()->json(['redirect_to' => $approveUrl]);
    }
}
