<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IpAssetGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class IpAssetGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = QueryBuilder::for(IpAssetGroup::class)
            ->withCount([
                'proxyIps',
                'proxyIps as available_ips_count' => fn($q) => $q->where('status', 'available'),
            ])
            ->allowedFilters([
                AllowedFilter::exact('source_type'),
                AllowedFilter::partial('source_name'),
                AllowedFilter::exact('country_code'),
            ])
            ->allowedSorts(['id', 'name', 'source_type', 'created_at'])
            ->defaultSort('-id')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:100',
            'source_type'  => 'required|string|max:50',
            'source_name'  => 'required|string|max:100',
            'country_code' => 'nullable|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'city'         => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:500',
            'api_config'   => 'nullable|array',
            'status'       => 'nullable|integer|in:0,1',
        ]);

        $data['created_by'] = $request->user()?->id;

        $group = IpAssetGroup::create($data);

        return $this->success($group, '资产组创建成功');
    }

    public function show(IpAssetGroup $ipAssetGroup): JsonResponse
    {
        $ipAssetGroup->loadCount([
            'proxyIps',
            'proxyIps as available_ips_count' => function ($query) {
                $query->where('status', 'available');
            },
        ]);

        return $this->success($ipAssetGroup);
    }

    public function update(Request $request, IpAssetGroup $ipAssetGroup): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'source_type'  => 'sometimes|string|max:50',
            'source_name'  => 'sometimes|string|max:100',
            'country_code' => 'nullable|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'city'         => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:500',
            'api_config'   => 'nullable|array',
            'status'       => 'nullable|integer|in:0,1',
        ]);

        $ipAssetGroup->update($data);

        return $this->success($ipAssetGroup, '资产组更新成功');
    }

    public function destroy(IpAssetGroup $ipAssetGroup): JsonResponse
    {
        // 有关联 IP 时不允许直接删除
        if ($ipAssetGroup->proxyIps()->count() > 0) {
            return $this->error('该资产组下还有 IP 资产，请先合并或迁移后再删除', 422);
        }

        $ipAssetGroup->delete();

        return $this->success(null, '资产组已删除');
    }

    /**
     * 合并资产组
     * POST /asset-groups/merge
     *
     * 将 source_ids 中所有资产组的 IP 迁移到 target_id，然后删除空的源资产组
     */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_ids' => 'required|array|min:1',
            'source_ids.*' => 'integer|exists:ip_asset_groups,id',
            'target_id' => 'required|integer|exists:ip_asset_groups,id',
        ]);

        $targetId = $data['target_id'];
        $sourceIds = array_filter($data['source_ids'], fn($id) => $id != $targetId);

        if (empty($sourceIds)) {
            return $this->error('源资产组不能和目标资产组相同', 422);
        }

        $target = IpAssetGroup::findOrFail($targetId);
        $stats = ['migrated_ips' => 0, 'deleted_groups' => 0, 'source_names' => []];

        \Illuminate\Support\Facades\DB::transaction(function () use ($sourceIds, $targetId, &$stats) {
            foreach ($sourceIds as $sourceId) {
                $source = IpAssetGroup::find($sourceId);
                if (!$source) continue;

                $stats['source_names'][] = $source->name;

                // 迁移该组下所有 IP
                $migrated = \App\Models\ProxyIp::where('asset_group_id', $sourceId)
                    ->update(['asset_group_id' => $targetId]);
                $stats['migrated_ips'] += $migrated;

                // 迁移导入日志
                \App\Models\IpImportLog::where('asset_group_id', $sourceId)
                    ->update(['asset_group_id' => $targetId]);

                // 删除空的源资产组
                $source->delete();
                $stats['deleted_groups']++;
            }
        });

        return $this->success($stats, sprintf(
            '已将「%s」合并到「%s」，迁移 %d 条 IP',
            implode('、', $stats['source_names']),
            $target->name,
            $stats['migrated_ips']
        ));
    }

    // 不分页，下拉选择用
    public function all(): JsonResponse
    {
        $groups = IpAssetGroup::where('status', 1)
            ->select('id', 'name', 'source_type', 'source_name', 'country_code', 'country_name', 'city')
            ->get();

        return $this->success($groups);
    }
}
