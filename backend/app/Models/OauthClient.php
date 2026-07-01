<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OauthClient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'client_id', 'client_secret', 'redirect_uris',
        'scopes', 'is_confidential', 'is_active', 'remark',
    ];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'scopes' => 'array',
            'is_confidential' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function generateClientId(): string
    {
        return 'oidc_' . bin2hex(random_bytes(16));
    }

    public static function generateClientSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris ?? [], true);
    }

    public function hasScope(string $scope): bool
    {
        return $this->scopes === null || in_array($scope, $this->scopes, true);
    }

    public function verifySecret(string $plain): bool
    {
        return password_verify($plain, $this->client_secret);
    }
}
