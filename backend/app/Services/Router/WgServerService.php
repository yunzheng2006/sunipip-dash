<?php

namespace App\Services\Router;

use App\Models\RouterDevice;
use App\Models\RouterDeviceWgPeer;
use App\Models\WgServer;

class WgServerService
{
    public function generateWgPeer(RouterDevice $device, WgServer $server): RouterDeviceWgPeer
    {
        $existing = RouterDeviceWgPeer::where('router_device_id', $device->id)
            ->where('wg_server_id', $server->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $assignedIp = $server->allocateIp();

        return RouterDeviceWgPeer::create([
            'router_device_id' => $device->id,
            'wg_server_id' => $server->id,
            'peer_public_key' => '',
            'peer_private_key' => '',
            'assigned_ip' => $assignedIp,
        ]);
    }

    public function getServerWgConfig(WgServer $server): string
    {
        $peers = $server->peers()->where('is_active', 1)->with('device')->get();

        $config = "[Interface]\n";
        $config .= "PrivateKey = {$server->private_key}\n";
        $config .= "Address = " . explode('/', $server->server_cidr)[0] . "/32\n";
        $config .= "ListenPort = {$server->listen_port}\n";

        if ($server->dns) {
            $config .= "DNS = {$server->dns}\n";
        }

        foreach ($peers as $peer) {
            $config .= "\n[Peer]\n";
            $config .= "PublicKey = {$peer->peer_public_key}\n";
            if ($peer->preshared_key) {
                $config .= "PresharedKey = {$peer->preshared_key}\n";
            }
            $config .= "AllowedIPs = {$peer->assigned_ip}\n";
            $hostname = $peer->device?->hostname ?? "device-{$peer->router_device_id}";
            $config .= "# {$hostname}\n";
        }

        return $config;
    }

    /**
     * Deploy a peer to a WG server via SSH (run wg set + wg-quick save).
     */
    public function deployPeerToServer(WgServer $server, RouterDeviceWgPeer $peer): bool
    {
        if (!$server->ssh_host || !$server->ssh_private_key) {
            \Log::warning("WG server {$server->name} missing SSH config, skipping peer deploy");
            return false;
        }

        $tmpKey = tempnam(sys_get_temp_dir(), 'wg_ssh_');
        file_put_contents($tmpKey, $server->ssh_private_key);
        chmod($tmpKey, 0600);

        try {
            $sshCmd = sprintf(
                'ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d %s@%s',
                escapeshellarg($tmpKey),
                $server->ssh_port ?? 22,
                escapeshellarg($server->ssh_user ?? 'root'),
                escapeshellarg($server->ssh_host)
            );

            $wgInterface = 'wg0';
            $addCmd = sprintf(
                '%s "wg set %s peer %s allowed-ips %s && wg-quick save %s"',
                $sshCmd,
                $wgInterface,
                escapeshellarg($peer->peer_public_key),
                escapeshellarg($peer->assigned_ip),
                $wgInterface
            );

            $output = [];
            $returnCode = 0;
            exec($addCmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                \Log::error("Failed to deploy peer to WG server", [
                    'server' => $server->name,
                    'peer_id' => $peer->id,
                    'output' => implode("\n", $output),
                ]);
                return false;
            }

            \Log::info("Deployed peer to WG server", [
                'server' => $server->name,
                'peer_id' => $peer->id,
                'public_key' => $peer->peer_public_key,
                'assigned_ip' => $peer->assigned_ip,
            ]);

            return true;
        } finally {
            @unlink($tmpKey);
        }
    }

    /**
     * Remove a peer from a WG server via SSH.
     */
    public function removePeerFromServer(WgServer $server, string $publicKey): bool
    {
        if (!$server->ssh_host || !$server->ssh_private_key) {
            \Log::warning("WG server {$server->name} missing SSH config, skipping peer removal");
            return false;
        }

        $tmpKey = tempnam(sys_get_temp_dir(), 'wg_ssh_');
        file_put_contents($tmpKey, $server->ssh_private_key);
        chmod($tmpKey, 0600);

        try {
            $sshCmd = sprintf(
                'ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d %s@%s',
                escapeshellarg($tmpKey),
                $server->ssh_port ?? 22,
                escapeshellarg($server->ssh_user ?? 'root'),
                escapeshellarg($server->ssh_host)
            );

            $wgInterface = 'wg0';
            $removeCmd = sprintf(
                '%s "wg set %s peer %s remove && wg-quick save %s"',
                $sshCmd,
                $wgInterface,
                escapeshellarg($publicKey),
                $wgInterface
            );

            $output = [];
            $returnCode = 0;
            exec($removeCmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                \Log::error("Failed to remove peer from WG server", [
                    'server' => $server->name,
                    'public_key' => $publicKey,
                    'output' => implode("\n", $output),
                ]);
                return false;
            }

            \Log::info("Removed peer from WG server", [
                'server' => $server->name,
                'public_key' => $publicKey,
            ]);

            return true;
        } finally {
            @unlink($tmpKey);
        }
    }

    /**
     * Deploy a peer to all active WG servers via SSH.
     */
    public function deployPeerToAllServers(RouterDeviceWgPeer $peer): array
    {
        $servers = WgServer::where('is_active', 1)->get();
        $results = ['success' => 0, 'failed' => 0];

        foreach ($servers as $server) {
            if ($this->deployPeerToServer($server, $peer)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Sync all active peers to a WG server via SSH.
     */
    public function syncAllPeersToServer(WgServer $server): array
    {
        $peers = $server->peers()->where('is_active', 1)->get();
        $results = ['success' => 0, 'failed' => 0, 'total' => $peers->count()];

        foreach ($peers as $peer) {
            if ($this->deployPeerToServer($server, $peer)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }
}
