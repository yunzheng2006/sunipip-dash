<?php

namespace App\Http\Controllers\Api\V1\Oidc;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OauthClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = OauthClient::orderByDesc('id')->get();
        return $this->success($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'redirect_uris' => 'required|array|min:1',
            'redirect_uris.*' => 'required|url',
            'scopes' => 'nullable|array',
            'is_confidential' => 'boolean',
            'remark' => 'nullable|string|max:500',
        ]);

        $plainSecret = OauthClient::generateClientSecret();

        $client = OauthClient::create([
            'name' => $data['name'],
            'client_id' => OauthClient::generateClientId(),
            'client_secret' => password_hash($plainSecret, PASSWORD_BCRYPT),
            'redirect_uris' => $data['redirect_uris'],
            'scopes' => $data['scopes'] ?? null,
            'is_confidential' => $data['is_confidential'] ?? true,
            'remark' => $data['remark'] ?? null,
        ]);

        return $this->success([
            'client' => $client,
            'client_secret_plain' => $plainSecret,
        ], '创建成功，请妥善保存 client_secret，此后将无法再次查看');
    }

    public function show(OauthClient $oauthClient): JsonResponse
    {
        return $this->success($oauthClient);
    }

    public function update(Request $request, OauthClient $oauthClient): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'redirect_uris' => 'sometimes|array|min:1',
            'redirect_uris.*' => 'required|url',
            'scopes' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'remark' => 'nullable|string|max:500',
        ]);

        $oauthClient->update($data);
        return $this->success($oauthClient->fresh());
    }

    public function destroy(OauthClient $oauthClient): JsonResponse
    {
        $oauthClient->delete();
        return $this->success(null, '已删除');
    }

    public function regenerateSecret(OauthClient $oauthClient): JsonResponse
    {
        $plainSecret = OauthClient::generateClientSecret();
        $oauthClient->update([
            'client_secret' => password_hash($plainSecret, PASSWORD_BCRYPT),
        ]);

        return $this->success([
            'client_secret_plain' => $plainSecret,
        ], '密钥已重新生成，请妥善保存');
    }
}
