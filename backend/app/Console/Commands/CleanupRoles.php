<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 彻底清理权限系统：删除所有旧角色和权限，只保留新体系
 *
 * 步骤：
 *   1. 记录每个用户当前的角色
 *   2. 删除所有角色和权限
 *   3. 重建新角色和权限（调用 permissions:setup --fresh）
 *   4. 根据映射重新分配角色
 */
class CleanupRoles extends Command
{
    protected $signature = 'roles:cleanup {--dry-run}';
    protected $description = '彻底清理重复权限组，重建新体系';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 1. 记录所有用户的角色
        $this->info('=== 步骤 1: 扫描用户角色 ===');
        $userRoles = [];
        $users = User::with('roles')->get();

        // 旧角色 → 新角色映射
        $mapping = [
            'super_admin'  => 'super_admin',
            '超级管理员'    => 'super_admin',
            'admin'        => 'manager',
            '管理员'       => 'manager',
            'staff'        => 'sales',
            '业务员'       => 'sales',
            '代理商'       => 'sales',
            'tech_admin'   => 'tech_admin',
            'ops_admin'    => 'ops_admin',
            'manager'      => 'manager',
            'sales'        => 'sales',
            '普通用户'     => null, // 不分配
        ];

        foreach ($users as $user) {
            $currentRoles = $user->roles->pluck('name')->toArray();
            if (empty($currentRoles)) continue;

            $newRoles = [];
            foreach ($currentRoles as $oldRole) {
                $mapped = $mapping[$oldRole] ?? null;
                if ($mapped && !in_array($mapped, $newRoles)) {
                    $newRoles[] = $mapped;
                }
            }

            if (!empty($newRoles)) {
                $userRoles[$user->id] = [
                    'name' => $user->name,
                    'old' => $currentRoles,
                    'new' => $newRoles,
                ];
            }

            $this->line("  #{$user->id} {$user->name}: [" . implode(', ', $currentRoles) . "] → [" . implode(', ', $newRoles) . "]");
        }

        // 确保 user #1 始终是 super_admin
        if (!isset($userRoles[1]) || !in_array('super_admin', $userRoles[1]['new'] ?? [])) {
            $u1 = User::find(1);
            if ($u1) {
                $userRoles[1] = [
                    'name' => $u1->name,
                    'old' => $userRoles[1]['old'] ?? [],
                    'new' => ['super_admin'],
                ];
                $this->warn("  ⚠ User #1 ({$u1->name}) 强制分配 super_admin");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY RUN] 以上为预览，不会执行任何操作');
            $this->info('去掉 --dry-run 参数以执行清理');
            return 0;
        }

        // 2. 清空所有角色和权限
        $this->newLine();
        $this->info('=== 步骤 2: 清空旧数据 ===');

        // 先解除所有用户的角色关联
        \DB::table('model_has_roles')->truncate();
        \DB::table('model_has_permissions')->truncate();
        \DB::table('role_has_permissions')->truncate();

        $deletedRoles = Role::count();
        $deletedPerms = Permission::count();
        Role::query()->delete();
        Permission::query()->delete();
        $this->info("  删除 {$deletedRoles} 个角色, {$deletedPerms} 个权限");

        // 3. 重建新体系
        $this->newLine();
        $this->info('=== 步骤 3: 重建权限体系 ===');
        $this->call('permissions:setup');

        // 4. 重新分配角色
        $this->newLine();
        $this->info('=== 步骤 4: 重新分配角色 ===');
        $assigned = 0;
        foreach ($userRoles as $userId => $info) {
            $user = User::find($userId);
            if (!$user) continue;

            $user->syncRoles($info['new']);
            $this->info("  #{$userId} {$info['name']}: → [" . implode(', ', $info['new']) . "]");
            $assigned++;
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->newLine();
        $this->info("完成！重新分配了 {$assigned} 个用户的角色");

        // 5. 最终状态
        $this->newLine();
        $this->info('=== 最终状态 ===');
        $roles = Role::withCount('permissions')->get();
        foreach ($roles as $role) {
            $userCount = \DB::table('model_has_roles')->where('role_id', $role->id)->count();
            $this->line("  {$role->name}: {$role->permissions_count} 权限, {$userCount} 用户");
        }

        return 0;
    }
}
