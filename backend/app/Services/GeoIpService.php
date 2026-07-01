<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeoIpService
{
    /**
     * Province suffix patterns to strip for normalization.
     */
    private const PROVINCE_SUFFIXES = [
        '壮族自治区',
        '回族自治区',
        '维吾尔自治区',
        '特别行政区',
        '自治区',
        '省',
        '市',
    ];

    /**
     * Resolve a single IP to its province name.
     */
    public function resolveProvince(string $ip): ?string
    {
        if ($this->isPrivateIp($ip)) {
            return null;
        }

        $results = $this->batchResolve([$ip]);

        return $results[$ip] ?? null;
    }

    /**
     * Batch-resolve IPs to province names.
     *
     * @param  array  $ips
     * @return array  ['ip' => 'province', ...]
     */
    public function batchResolve(array $ips): array
    {
        $ips = array_values(array_unique($ips));
        $results = [];
        $uncached = [];

        // 1. Check cache & filter private IPs
        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                $results[$ip] = null;
                continue;
            }

            $cached = Cache::get($this->cacheKey($ip));
            if ($cached !== null) {
                $results[$ip] = $cached === '__null__' ? null : $cached;
            } else {
                $uncached[] = $ip;
            }
        }

        if (empty($uncached)) {
            return $results;
        }

        // 2. Batch API calls (max 100 per call, max 2 calls)
        $chunks = array_chunk($uncached, 100);
        $chunks = array_slice($chunks, 0, 2); // max 2 batch calls

        foreach ($chunks as $chunk) {
            $resolved = $this->callBatchApi($chunk);
            foreach ($resolved as $ip => $province) {
                $results[$ip] = $province;
                Cache::put($this->cacheKey($ip), $province ?? '__null__', now()->addDays(30));
            }
        }

        // Any IPs beyond the 2-batch limit get '未知'
        $resolvedIps = array_keys($results);
        foreach ($uncached as $ip) {
            if (!in_array($ip, $resolvedIps, true)) {
                $results[$ip] = '未知';
                Cache::put($this->cacheKey($ip), '未知', now()->addDays(30));
            }
        }

        return $results;
    }

    /**
     * Call ip-api.com batch endpoint.
     *
     * @param  array  $ips
     * @return array  ['ip' => 'province'|'country'|'未知', ...]
     */
    private function callBatchApi(array $ips): array
    {
        $results = [];

        try {
            $payload = array_map(fn (string $ip) => [
                'query' => $ip,
                'fields' => 'query,status,regionName,country,countryCode',
                'lang' => 'zh-CN',
            ], $ips);

            $response = Http::timeout(10)
                ->post('http://ip-api.com/batch?lang=zh-CN', $payload);

            if ($response->successful()) {
                foreach ($response->json() as $item) {
                    $ip = $item['query'] ?? null;
                    if (!$ip) continue;

                    if (($item['status'] ?? '') !== 'success') {
                        $results[$ip] = '未知';
                        continue;
                    }

                    $cc = $item['countryCode'] ?? '';
                    $region = $item['regionName'] ?? '';
                    $country = $item['country'] ?? '';

                    $results[$ip] = match ($cc) {
                        'CN' => $this->normalizeProvince($region) ?: '未知',
                        'HK' => '香港',
                        'TW' => '台湾',
                        'MO' => '澳门',
                        default => $country ?: '未知',
                    };
                }
            }
        } catch (\Throwable $e) {
            // Graceful degradation: mark all as unknown
        }

        foreach ($ips as $ip) {
            if (!isset($results[$ip])) {
                $results[$ip] = '未知';
            }
        }

        return $results;
    }

    /**
     * Strip trailing province/city/region suffixes.
     */
    private function normalizeProvince(string $name): string
    {
        foreach (self::PROVINCE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && mb_strlen($name) > mb_strlen($suffix)) {
                return mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix));
            }
        }

        return $name;
    }

    /**
     * Check if an IP is private/local.
     */
    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function cacheKey(string $ip): string
    {
        return "geoip:province:{$ip}";
    }
}
