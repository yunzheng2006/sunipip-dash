<?php

return [
    'issuer' => env('OIDC_ISSUER', env('APP_URL')),
    'private_key' => env('OIDC_PRIVATE_KEY', storage_path('oidc/private.pem')),
    'public_key' => env('OIDC_PUBLIC_KEY', storage_path('oidc/public.pem')),
    'kid_file' => env('OIDC_KID_FILE', storage_path('oidc/kid.txt')),
    'authorization_code_ttl' => 600,
    'access_token_ttl' => 3600,
    'scopes_supported' => ['openid', 'profile', 'email', 'phone'],
    'claims_supported' => [
        'sub', 'iss', 'aud', 'exp', 'iat', 'auth_time',
        'name', 'preferred_username', 'email', 'email_verified',
        'phone_number', 'phone_number_verified', 'company_name', 'updated_at',
    ],
];
