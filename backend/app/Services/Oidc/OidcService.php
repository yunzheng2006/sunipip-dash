<?php

namespace App\Services\Oidc;

use App\Models\Customer;
use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

class OidcService
{
    private ?string $privateKey = null;
    private ?string $publicKey = null;

    public function getPrivateKey(): string
    {
        if (!$this->privateKey) {
            $path = config('oidc.private_key');
            if (!file_exists($path)) {
                throw new \RuntimeException('OIDC private key not found. Run: php artisan oidc:generate-keys');
            }
            $this->privateKey = file_get_contents($path);
        }
        return $this->privateKey;
    }

    public function getPublicKey(): string
    {
        if (!$this->publicKey) {
            $path = config('oidc.public_key');
            if (!file_exists($path)) {
                throw new \RuntimeException('OIDC public key not found. Run: php artisan oidc:generate-keys');
            }
            $this->publicKey = file_get_contents($path);
        }
        return $this->publicKey;
    }

    public function getKid(): string
    {
        $path = config('oidc.kid_file');
        if (!file_exists($path)) {
            throw new \RuntimeException('OIDC kid file not found. Run: php artisan oidc:generate-keys');
        }
        return trim(file_get_contents($path));
    }

    public function getJwks(): array
    {
        $publicKey = openssl_pkey_get_public($this->getPublicKey());
        $details = openssl_pkey_get_details($publicKey);

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->getKid(),
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ]],
        ];
    }

    public function createAuthorizationCode(
        OauthClient $client,
        Customer $customer,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $nonce
    ): OauthAuthorizationCode {
        return OauthAuthorizationCode::create([
            'code' => bin2hex(random_bytes(32)),
            'client_id' => $client->client_id,
            'customer_id' => $customer->id,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'nonce' => $nonce,
            'expires_at' => now()->addSeconds(config('oidc.authorization_code_ttl')),
            'created_at' => now(),
        ]);
    }

    public function exchangeAuthorizationCode(
        string $code,
        string $clientId,
        string $redirectUri,
        ?string $codeVerifier
    ): array {
        $authCode = OauthAuthorizationCode::where('code', $code)->first();

        if (!$authCode) {
            throw new \Exception('Invalid authorization code');
        }
        if ($authCode->isUsed()) {
            throw new \Exception('Authorization code already used');
        }
        if ($authCode->isExpired()) {
            throw new \Exception('Authorization code expired');
        }
        if ($authCode->client_id !== $clientId) {
            throw new \Exception('Client mismatch');
        }
        if ($authCode->redirect_uri !== $redirectUri) {
            throw new \Exception('Redirect URI mismatch');
        }

        // PKCE verification
        if ($authCode->code_challenge) {
            if (!$codeVerifier) {
                throw new \Exception('Code verifier required');
            }
            $expected = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($authCode->code_challenge, $expected)) {
                throw new \Exception('Invalid code verifier');
            }
        }

        // Mark as used
        $authCode->update(['used_at' => now()]);

        $customer = Customer::findOrFail($authCode->customer_id);
        $client = OauthClient::where('client_id', $clientId)->firstOrFail();
        $scopes = $authCode->scopes;

        $accessToken = $this->buildAccessToken($customer, $client, $scopes);
        $idToken = in_array('openid', $scopes)
            ? $this->buildIdToken($customer, $client, $scopes, $authCode->nonce)
            : null;

        $response = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => config('oidc.access_token_ttl'),
            'scope' => implode(' ', $scopes),
        ];

        if ($idToken) {
            $response['id_token'] = $idToken;
        }

        return $response;
    }

    public function buildIdToken(Customer $customer, OauthClient $client, array $scopes, ?string $nonce): string
    {
        $now = time();
        $claims = [
            'iss' => config('oidc.issuer'),
            'sub' => (string) $customer->id,
            'aud' => $client->client_id,
            'exp' => $now + config('oidc.access_token_ttl'),
            'iat' => $now,
            'auth_time' => $now,
        ];

        if ($nonce) {
            $claims['nonce'] = $nonce;
        }

        $claims = array_merge($claims, $this->getCustomerClaims($customer, $scopes));

        return JWT::encode($claims, $this->getPrivateKey(), 'RS256', $this->getKid());
    }

    public function buildAccessToken(Customer $customer, OauthClient $client, array $scopes): string
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));

        OauthAccessToken::create([
            'jti' => $jti,
            'client_id' => $client->client_id,
            'customer_id' => $customer->id,
            'scopes' => $scopes,
            'expires_at' => now()->addSeconds(config('oidc.access_token_ttl')),
            'created_at' => now(),
        ]);

        return JWT::encode([
            'iss' => config('oidc.issuer'),
            'sub' => (string) $customer->id,
            'aud' => $client->client_id,
            'exp' => $now + config('oidc.access_token_ttl'),
            'iat' => $now,
            'jti' => $jti,
            'scope' => implode(' ', $scopes),
        ], $this->getPrivateKey(), 'RS256', $this->getKid());
    }

    public function validateAccessToken(string $token): ?OauthAccessToken
    {
        try {
            $decoded = JWT::decode($token, new Key($this->getPublicKey(), 'RS256'));
        } catch (\Throwable) {
            return null;
        }

        if (empty($decoded->jti)) {
            return null;
        }

        $record = OauthAccessToken::where('jti', $decoded->jti)->first();
        if (!$record || $record->isRevoked()) {
            return null;
        }
        if ($record->expires_at->isPast()) {
            return null;
        }

        return $record;
    }

    public function getCustomerClaims(Customer $customer, array $scopes): array
    {
        $claims = [];

        if (in_array('profile', $scopes)) {
            $claims['name'] = $customer->display_name ?: $customer->customer_name;
            $claims['preferred_username'] = $customer->username;
            if ($customer->company_name) {
                $claims['company_name'] = $customer->company_name;
            }
            if ($customer->updated_at) {
                $claims['updated_at'] = $customer->updated_at->timestamp;
            }
        }

        if (in_array('email', $scopes)) {
            $claims['email'] = $customer->email;
            $claims['email_verified'] = $customer->email_verified_at !== null;
        }

        if (in_array('phone', $scopes)) {
            $claims['phone_number'] = $customer->phone;
            $claims['phone_number_verified'] = $customer->verified_at !== null;
        }

        return $claims;
    }

    public function getDiscoveryDocument(): array
    {
        $issuer = config('oidc.issuer');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/api/v1/oauth/authorize',
            'token_endpoint' => $issuer . '/api/v1/oauth/token',
            'userinfo_endpoint' => $issuer . '/api/v1/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => config('oidc.scopes_supported'),
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'claims_supported' => config('oidc.claims_supported'),
            'code_challenge_methods_supported' => ['S256'],
        ];
    }
}
