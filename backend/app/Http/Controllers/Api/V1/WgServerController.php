<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WgServer;
use App\Services\Router\WgServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WgServerController extends Controller
{
    public function __construct(
        private WgServerService $wgService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $servers = QueryBuilder::for(WgServer::class)
            ->withCount('peers')
            ->allowedFilters([
                AllowedFilter::exact('is_active'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['id', 'name', 'created_at'])
            ->defaultSort('id')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($servers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'endpoint' => 'required|string|max:255',
            'public_key' => 'required|string|max:64',
            'private_key' => 'required|string',
            'listen_port' => 'nullable|integer|min:1|max:65535',
            'server_cidr' => 'required|string|max:20',
            'dns' => 'nullable|string|max:100',
            'mtu' => 'nullable|integer|min:1280|max:1500',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string|max:500',
            'ssh_host' => 'nullable|string|max:255',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_user' => 'nullable|string|max:100',
            'ssh_private_key' => 'nullable|string',
            'role' => 'nullable|string|in:primary,backup',
        ]);

        $server = WgServer::create($data);

        return $this->success($server, 'WireGuard 服务器已创建');
    }

    public function show(WgServer $wgServer): JsonResponse
    {
        $wgServer->loadCount('peers');
        $wgServer->active_peers_count = $wgServer->peers()->where('is_active', 1)->count();

        return $this->success($wgServer);
    }

    public function update(Request $request, WgServer $wgServer): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'endpoint' => 'sometimes|string|max:255',
            'public_key' => 'sometimes|string|max:64',
            'private_key' => 'nullable|string',
            'listen_port' => 'nullable|integer|min:1|max:65535',
            'server_cidr' => 'sometimes|string|max:20',
            'dns' => 'nullable|string|max:100',
            'mtu' => 'nullable|integer|min:1280|max:1500',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string|max:500',
            'ssh_host' => 'nullable|string|max:255',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_user' => 'nullable|string|max:100',
            'ssh_private_key' => 'nullable|string',
            'role' => 'nullable|string|in:primary,backup',
        ]);

        $wgServer->update(array_filter($data, fn ($v) => $v !== null));

        return $this->success($wgServer->fresh(), 'WireGuard 服务器已更新');
    }

    public function destroy(WgServer $wgServer): JsonResponse
    {
        $activePeers = $wgServer->peers()->where('is_active', 1)->count();
        if ($activePeers > 0) {
            return $this->error("该服务器有 {$activePeers} 个活跃 Peer，无法删除", 422);
        }

        $wgServer->delete();

        return $this->success(null, 'WireGuard 服务器已删除');
    }

    public function serverConfig(WgServer $wgServer): JsonResponse
    {
        $config = $this->wgService->getServerWgConfig($wgServer);

        return $this->success([
            'config' => $config,
            'server_name' => $wgServer->name,
        ]);
    }

    /**
     * Sync all active peers to the WG server via SSH.
     */
    public function syncPeers(WgServer $wgServer): JsonResponse
    {
        if (!$wgServer->ssh_host || !$wgServer->ssh_private_key) {
            return $this->error('该服务器未配置 SSH 连接信息', 422);
        }

        $results = $this->wgService->syncAllPeersToServer($wgServer);

        return $this->success($results, sprintf(
            '同步完成: %d 成功, %d 失败 (共 %d 个 Peer)',
            $results['success'],
            $results['failed'],
            $results['total']
        ));
    }

    /**
     * Deploy a single peer to this WG server via SSH.
     */
    public function deployPeer(WgServer $wgServer, Request $request): JsonResponse
    {
        $data = $request->validate([
            'peer_id' => 'required|integer|exists:router_device_wg_peers,id',
        ]);

        if (!$wgServer->ssh_host || !$wgServer->ssh_private_key) {
            return $this->error('该服务器未配置 SSH 连接信息', 422);
        }

        $peer = \App\Models\RouterDeviceWgPeer::findOrFail($data['peer_id']);
        $ok = $this->wgService->deployPeerToServer($wgServer, $peer);

        if ($ok) {
            return $this->success(null, 'Peer 已部署到服务器');
        }

        return $this->error('Peer 部署失败，请查看日志', 500);
    }
}
