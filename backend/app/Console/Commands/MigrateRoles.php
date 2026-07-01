<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * 迁移旧角色到新角色体系，然后删除旧角色
 *
 * 旧角色映射：
 *   超级管理员 → super_admin
 *   管理员     → manager
 *   业务员     → sales
 *   代理商     → sales
 *   普通用户   → (删除，不分配)
 */
class MigrateRoles extends Command
{
    protected $signature = 'roles:migrate {--dry-run}';
    protected $description = '将旧角色用户迁移到新角色体系';

    private array $mapping = [
        '超级管理员' => 'super_admin',
        '管理员'     => 'manager',
        '业务员'     => 'sales',
        '代理商'     => 'sales',
        '普通用户'   => null,
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Ensure new roles exist
        foreach (['super_admin', 'tech_admin', 'ops_admin', 'manager', 'sales'] as $r) {
            if (!Role::where('name', $r)->exists()) {
                $this->error("新角色 '{$r}' 不存在，请先运行 php artisan permissions:setup");
                return 1;
            }
        }

        $migrated = 0;

        foreach ($this->mapping as $oldName => $newName) {
            $oldRole = Role::where('name', $oldName)->first();
            if (!$oldRole) continue;

            $users = User::role($oldName)->get();
            $this->info("旧角色「{$oldName}」→ " . ($newName ?: '(不分配)') . "：{$users->count()} 个用户");

            foreach ($users as $user) {
                if ($dryRun) {
                    $this->line("  [DRY] #{$user->id} {$user->name}: {$oldName} → " . ($newName ?: '无'));
                } else {
                    // Remove old role
                    $user->removeRole($oldName);
                    // Add new role (if mapped)
                    if ($newName) {
                        $user->assignRole($newName);
                    }
                    $this->line("  #{$user->id} {$user->name}: {$oldName} → " . ($newName ?: '无'));
                }
                $migrated++;
            }
        }

        // Delete old roles
        if (!$dryRun) {
            $deleted = 0;
            foreach (array_keys($this->mapping) as $oldName) {
                $old = Role::where('name', $oldName)->first();
                if ($old) {
                    $old->delete();
                    $deleted++;
                }
            }
            $this->info("已删除 {$deleted} 个旧角色");
        }

        $this->newLine();
        $this->info($dryRun ? "[DRY RUN] 预计迁移 {$migrated} 个用户" : "完成，迁移 {$migrated} 个用户");

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        return 0;
    }
}
